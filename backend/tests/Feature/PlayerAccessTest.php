<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalTask;
use App\Models\ExternalTask;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PlayerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->seed();
        config(['football.worker_key' => 'worker-a', 'football.credentials.FOOTBALL_TOKEN_A' => 'fake-token', 'football.base_url' => 'https://provider.test']);
        $this->signIn('admin');
    }

    private function signIn(string $role): void
    {
        $user = User::firstOrCreate(['email' => $role.'@player-access.test'], ['name' => $role, 'password' => 'password-demo-123', 'is_active' => true]);
        $user->roles()->sync(Role::where('code', $role)->pluck('id'));
        Passport::actingAs($user);
    }

    private function runTask(): void
    {
        app()->call([new ProcessExternalTask(ExternalTask::firstOrFail()->id), 'handle']);
    }

    public function test_unknown_player_get_and_refresh_share_one_async_import(): void
    {
        $id = $this->getJson('/api/players/81187')->assertAccepted()
            ->assertJsonPath('data.player.id', 81187)
            ->assertJsonPath('data.has_data', false)
            ->assertJsonPath('data.import.status', 'pending')->json('data.import.id');
        $this->getJson('/api/players/81187')->assertAccepted()->assertJsonPath('data.import.id', $id);
        $this->postJson('/api/players/81187/refresh')->assertAccepted()->assertJsonPath('data.id', $id);
        $this->getJson('/api/players')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseCount('imports', 1);
        $this->assertDatabaseCount('external_tasks', 1);
        $this->assertDatabaseHas('external_tasks', ['resource' => 'player_detail:81187']);
        Queue::assertPushed(ProcessExternalTask::class, 1);
        Http::assertNothingSent();
    }

    public function test_direct_refresh_can_request_an_unknown_player(): void
    {
        $id = $this->postJson('/api/players/81187/refresh')->assertAccepted()->json('data.id');
        $this->getJson('/api/players/81187')->assertAccepted()->assertJsonPath('data.import.id', $id);
        $this->assertDatabaseCount('imports', 1);
        Queue::assertPushed(ProcessExternalTask::class, 1);
        Http::assertNothingSent();
    }

    public function test_forbidden_person_detail_preserves_provider_diagnosis_without_publishing_or_reimporting(): void
    {
        $id = $this->getJson('/api/players/81187')->assertAccepted()->json('data.import.id');
        $providerMessage = 'The resource you are looking for is restricted and apparently not within your permissions. Please check your subscription.';
        Http::fake(['https://provider.test/persons/81187' => Http::response(['message' => $providerMessage, 'errorCode' => 403], 403)]);
        $this->runTask();
        $message = 'Solicitud externa fallida (HTTP 403). '.$providerMessage;
        $this->getJson('/api/players/81187')->assertOk()
            ->assertJsonPath('data.has_data', false)
            ->assertJsonPath('data.import.status', 'failed')
            ->assertJsonPath('data.import.error_code', 'SOURCE_ACCESS_DENIED')
            ->assertJsonPath('data.import.error_message', $message);
        $this->getJson('/api/imports/'.$id)->assertOk()->assertJsonPath('data.error_message', $message);
        $this->getJson('/api/players')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertNull(Player::firstOrFail()->first_published_at);
        $this->assertDatabaseCount('imports', 1);
        Queue::assertPushed(ProcessExternalTask::class, 1);
        Http::assertSentCount(1);
    }

    public function test_readonly_cannot_create_or_import_missing_or_unpublished_players(): void
    {
        $this->signIn('readonly');
        foreach ([false, true] as $exists) {
            if ($exists) {
                Player::create(['external_id' => 81187, 'name' => 'Unpublished player']);
            }
            $response = $this->getJson('/api/players/81187')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
            $this->assertStringContainsString('No hay información publicada de este jugador', $response->json('message'));
            $this->assertStringNotContainsString('App\\Models', $response->json('message'));
            $this->postJson('/api/players/81187/refresh')->assertForbidden();
            $this->assertDatabaseCount('players', $exists ? 1 : 0);
        }
        $this->assertDatabaseCount('imports', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_valid_person_detail_publishes_player_with_shirt_number_and_one_request(): void
    {
        $this->getJson('/api/players/81187')->assertAccepted();
        Http::fake(['https://provider.test/persons/81187' => Http::response([
            'id' => 81187, 'name' => 'Abdelkabir Abqar', 'firstName' => 'Abdelkabir', 'lastName' => 'Abqar',
            'dateOfBirth' => '1999-03-10', 'nationality' => 'Morocco', 'position' => 'Centre-Back', 'shirtNumber' => 5,
            'lastUpdated' => '2026-10-10T15:30:00Z',
            'currentTeam' => ['id' => 82, 'name' => 'Getafe CF', 'contract' => ['start' => '2025-07', 'until' => '2028-06']],
        ])]);
        $this->runTask();
        $this->getJson('/api/players/81187')->assertOk()
            ->assertJsonPath('data.has_data', true)
            ->assertJsonPath('data.player.name', 'Abdelkabir Abqar')
            ->assertJsonPath('data.player.shirt_number', 5)
            ->assertJsonPath('data.player.source_updated_at', '2026-10-10T15:30:00Z')
            ->assertJsonPath('data.player.current_team.contract.until', '2028-06')
            ->assertJsonPath('data.import.status', 'completed');
        $this->signIn('readonly');
        $this->getJson('/api/players')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', 81187);
        $this->getJson('/api/players/81187')->assertOk()->assertJsonPath('data.has_data', true);
        $this->assertNotNull(Player::firstOrFail()->first_published_at);
        $this->assertDatabaseCount('competition_snapshots', 0);
        $this->assertDatabaseCount('teams', 0);
        Http::assertSentCount(1);
    }

    public function test_invalid_person_detail_leaves_the_reference_unpublished(): void
    {
        $this->getJson('/api/players/81187')->assertAccepted();
        Http::fake(['https://provider.test/persons/81187' => Http::response(['id' => 999, 'name' => 'Wrong person', 'shirtNumber' => 5])]);
        $this->runTask();
        $this->getJson('/api/players/81187')->assertOk()
            ->assertJsonPath('data.has_data', false)
            ->assertJsonPath('data.import.status', 'failed')
            ->assertJsonPath('data.import.error_code', 'SOURCE_PLAYER_MISMATCH');
        $this->getJson('/api/players')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertNull(Player::firstOrFail()->first_published_at);
        Http::assertSentCount(1);
    }
}
