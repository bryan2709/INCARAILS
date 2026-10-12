<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalTask;
use App\Models\Competition;
use App\Models\ExternalTask;
use App\Models\Import;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\Support\BuildsCompetitionImports;
use Tests\TestCase;

class CompetitionAccessTest extends TestCase
{
    use BuildsCompetitionImports, RefreshDatabase;

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
        $user = User::firstOrCreate(['email' => $role.'@competition.test'], ['name' => $role, 'password' => 'password-demo-123', 'is_active' => true]);
        $user->roles()->sync(Role::where('code', $role)->pluck('id'));
        Passport::actingAs($user);
    }

    public function test_unknown_competition_starts_one_async_import_for_authorized_users(): void
    {
        $first = $this->getJson('/api/competitions/2145')->assertAccepted()
            ->assertJsonPath('data.competition.id', 2145)
            ->assertJsonPath('data.snapshot', null)
            ->assertJsonPath('data.import.status', 'pending')->json('data.import.id');
        $this->getJson('/api/competitions/2145')->assertAccepted()->assertJsonPath('data.import.id', $first);
        $this->assertDatabaseCount('competitions', 1);
        $this->assertDatabaseCount('imports', 1);
        $this->assertDatabaseHas('external_tasks', ['resource' => 'competition_detail:2145']);
        Queue::assertPushed(ProcessExternalTask::class, 1);
        Http::assertNothingSent();
    }

    public function test_unknown_competition_forbidden_response_preserves_the_provider_message_without_reimporting(): void
    {
        $id = $this->getJson('/api/competitions/2145')->assertAccepted()->json('data.import.id');
        $providerMessage = 'The resource you are looking for is restricted and apparently not within your permissions. Please check your subscription.';
        Http::fake(['https://provider.test/competitions/2145' => Http::response(['message' => $providerMessage, 'errorCode' => 403], 403)]);
        $task = ExternalTask::firstOrFail();
        app()->call([new ProcessExternalTask($task->id), 'handle']);
        $message = 'Solicitud externa fallida (HTTP 403). '.$providerMessage;

        $this->getJson('/api/competitions/2145')->assertOk()
            ->assertJsonPath('data.snapshot', null)
            ->assertJsonPath('data.import.status', 'failed')
            ->assertJsonPath('data.import.error_code', 'SOURCE_ACCESS_DENIED')
            ->assertJsonPath('data.import.error_message', $message);
        $this->getJson('/api/imports/'.$id)->assertOk()->assertJsonPath('data.error_message', $message);
        $this->assertDatabaseCount('imports', 1);
        $this->assertDatabaseCount('competition_snapshots', 0);
        Queue::assertPushed(ProcessExternalTask::class, 1);
        Http::assertSentCount(1);
    }

    public function test_readonly_missing_competition_returns_a_public_message_without_creating_data(): void
    {
        $this->signIn('readonly');
        $response = $this->getJson('/api/competitions/2145')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->assertStringContainsString('No hay información local', $response->json('message'));
        $this->assertStringNotContainsString('App\\Models', $response->json('message'));
        $this->assertDatabaseCount('competitions', 0);
        $this->assertDatabaseCount('imports', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_discovered_placeholder_does_not_appear_in_or_block_the_catalog(): void
    {
        $this->getJson('/api/competitions/2145')->assertAccepted();
        $this->getJson('/api/competitions')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseHas('imports', ['type' => 'catalog', 'status' => 'pending']);
        $this->assertDatabaseCount('imports', 2);
        $this->getJson('/api/competitions')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('imports', 2);
        Queue::assertPushed(ProcessExternalTask::class, 2);
        Http::assertNothingSent();
    }

    public function test_successful_direct_discovery_publishes_complete_identity_and_is_listed(): void
    {
        $id = $this->getJson('/api/competitions/2145')->assertAccepted()->json('data.import.id');
        $import = Import::findOrFail($id);
        $this->completeTask($import, 'detail', [
            'id' => 2145, 'name' => 'Discovered cup', 'code' => 'TEST', 'type' => 'CUP', 'emblem' => 'https://provider.test/cup.svg',
            'currentSeason' => ['id' => 2509, 'startDate' => '2026-08-14', 'endDate' => '2027-05-01'],
        ]);
        $this->completeTask($import, 'teams', $this->sourceTeams(2145));
        $this->getJson('/api/competitions/2145')->assertOk()
            ->assertJsonPath('data.competition.name', 'Discovered cup')
            ->assertJsonPath('data.competition.code', 'TEST')
            ->assertJsonPath('data.competition.type', 'CUP')
            ->assertJsonPath('data.competition.emblem', 'https://provider.test/cup.svg')
            ->assertJsonPath('data.snapshot.teams.0.id', 59);
        $this->signIn('readonly');
        $this->getJson('/api/competitions')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', 2145);
        $this->assertNull(Competition::firstOrFail()->catalog_fetched_at);
        Http::assertNothingSent();
    }
}
