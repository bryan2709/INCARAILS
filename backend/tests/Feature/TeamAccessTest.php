<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalTask;
use App\Models\ExternalTask;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TeamAccessTest extends TestCase
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
        $user = User::firstOrCreate(['email' => $role.'@team-access.test'], ['name' => $role, 'password' => 'password-demo-123', 'is_active' => true]);
        $user->roles()->sync(Role::where('code', $role)->pluck('id'));
        Passport::actingAs($user);
    }

    private function runTask(): void
    {
        app()->call([new ProcessExternalTask(ExternalTask::firstOrFail()->id), 'handle']);
    }

    public function test_unknown_team_get_and_refresh_share_one_async_import(): void
    {
        $id = $this->getJson('/api/team/8772')->assertAccepted()
            ->assertJsonPath('data.team.id', 8772)
            ->assertJsonPath('data.has_data', false)
            ->assertJsonPath('data.import.status', 'pending')->json('data.import.id');
        $this->getJson('/api/team/8772')->assertAccepted()->assertJsonPath('data.import.id', $id);
        $this->postJson('/api/team/8772/refresh')->assertAccepted()->assertJsonPath('data.id', $id);
        $this->getJson('/api/team')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('imports', 1);
        $this->assertDatabaseHas('external_tasks', ['resource' => 'team_detail:8772']);
        Queue::assertPushed(ProcessExternalTask::class, 1);
        Http::assertNothingSent();
    }

    public function test_direct_refresh_can_request_an_unknown_team(): void
    {
        $id = $this->postJson('/api/team/8772/refresh')->assertAccepted()->json('data.id');
        $this->getJson('/api/team/8772')->assertAccepted()->assertJsonPath('data.import.id', $id);
        $this->assertDatabaseCount('imports', 1);
        Http::assertNothingSent();
    }

    public function test_forbidden_team_detail_preserves_provider_diagnosis_without_publishing_or_reimporting(): void
    {
        $id = $this->getJson('/api/team/8772')->assertAccepted()->json('data.import.id');
        $providerMessage = 'The resource you are looking for is restricted and apparently not within your permissions. Please check your subscription.';
        Http::fake(['https://provider.test/teams/8772' => Http::response(['message' => $providerMessage, 'errorCode' => 403], 403)]);
        $this->runTask();
        $message = 'Solicitud externa fallida (HTTP 403). '.$providerMessage;
        $this->getJson('/api/team/8772')->assertOk()
            ->assertJsonPath('data.has_data', false)
            ->assertJsonPath('data.import.status', 'failed')
            ->assertJsonPath('data.import.error_code', 'SOURCE_ACCESS_DENIED')
            ->assertJsonPath('data.import.error_message', $message);
        $this->getJson('/api/imports/'.$id)->assertOk()->assertJsonPath('data.error_message', $message);
        $this->getJson('/api/team')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertNull(Team::firstOrFail()->first_published_at);
        $this->assertDatabaseCount('imports', 1);
        Queue::assertPushed(ProcessExternalTask::class, 1);
        Http::assertSentCount(1);
    }

    public function test_readonly_cannot_create_or_import_missing_or_unpublished_teams(): void
    {
        $this->signIn('readonly');
        foreach ([false, true] as $exists) {
            if ($exists) {
                Team::create(['external_id' => 8772, 'name' => 'Unpublished team']);
            }
            $response = $this->getJson('/api/team/8772')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
            $this->assertStringContainsString('No hay información publicada', $response->json('message'));
            $this->assertStringNotContainsString('App\\Models', $response->json('message'));
            $this->postJson('/api/team/8772/refresh')->assertForbidden();
            $this->assertDatabaseCount('teams', $exists ? 1 : 0);
        }
        $this->assertDatabaseCount('imports', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_valid_detail_publishes_team_and_roster_with_one_request_and_no_competition_import(): void
    {
        $this->getJson('/api/team/8772')->assertAccepted();
        Http::fake(['https://provider.test/teams/8772' => Http::response([
            'id' => 8772, 'name' => 'Discovered team', 'shortName' => 'Discovered',
            'area' => ['id' => 2072, 'name' => 'England'],
            'squad' => [['id' => 81187, 'name' => 'Published player', 'position' => 'Defence', 'shirtNumber' => 5]],
        ])]);
        $this->runTask();
        $this->getJson('/api/team/8772')->assertOk()
            ->assertJsonPath('data.has_data', true)
            ->assertJsonPath('data.team.name', 'Discovered team')
            ->assertJsonPath('data.team.squad.0.shirt_number', 5)
            ->assertJsonPath('data.import.status', 'completed');
        $this->signIn('readonly');
        $this->getJson('/api/team')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', 8772);
        $this->getJson('/api/team/8772')->assertOk()->assertJsonPath('data.has_data', true);
        $this->getJson('/api/players/81187')->assertOk();
        $this->assertNotNull(Team::firstOrFail()->first_published_at);
        $this->assertDatabaseCount('competition_snapshots', 0);
        Http::assertSentCount(1);
    }

    public function test_invalid_detail_leaves_the_discovered_team_unpublished(): void
    {
        $this->getJson('/api/team/8772')->assertAccepted();
        Http::fake(['https://provider.test/teams/8772' => Http::response(['id' => 999, 'name' => 'Wrong team', 'squad' => []])]);
        $this->runTask();
        $this->getJson('/api/team/8772')->assertOk()
            ->assertJsonPath('data.has_data', false)
            ->assertJsonPath('data.import.status', 'failed')
            ->assertJsonPath('data.import.error_code', 'SOURCE_TEAM_MISMATCH');
        $this->getJson('/api/team')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertNull(Team::firstOrFail()->first_published_at);
        Http::assertSentCount(1);
    }
}
