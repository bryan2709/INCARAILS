<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalTask;
use App\Models\Competition;
use App\Models\ExternalTask;
use App\Models\Import;
use App\Models\Player;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\ImportCoordinator;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\Support\BuildsCompetitionImports;
use Tests\TestCase;

class EntityRefreshTest extends TestCase
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
        $user = User::firstOrCreate(['email' => $role.'@refresh.test'], ['name' => $role, 'password' => 'password-demo-123', 'is_active' => true]);
        $user->roles()->sync(Role::where('code', $role)->pluck('id'));
        Passport::actingAs($user);
    }

    private function galatasaray(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/galatasaray-team.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function team(): Team
    {
        return Team::create(['external_id' => 610, 'name' => 'Galatasaray SK', 'first_published_at' => now()]);
    }

    private function runTask(Import $import): void
    {
        $task = ExternalTask::whereHas('step', fn ($query) => $query->where('import_id', $import->id))->firstOrFail();
        app()->call([new ProcessExternalTask($task->id), 'handle']);
    }

    public function test_team_refresh_is_async_idempotent_and_requires_manage_permission(): void
    {
        $this->team();
        $first = $this->postJson('/api/team/610/refresh')->assertAccepted()->json('data.id');
        $second = $this->postJson('/api/team/610/refresh')->assertAccepted()->json('data.id');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('imports', 1);
        $this->assertDatabaseCount('external_tasks', 1);
        $this->assertDatabaseHas('external_tasks', ['resource' => 'team_detail:610']);
        $this->signIn('readonly');
        $this->postJson('/api/team/610/refresh')->assertForbidden();
        $this->postJson('/api/players/4204/refresh')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_team_detail_publishes_all_32_players_without_changing_competition_squads(): void
    {
        $competition = Competition::create(['external_id' => 2001, 'name' => 'UEFA Champions League']);
        $initial = $this->prepareImport($competition, 2557);
        $source = $this->sourceTeams(2001, 2557);
        $source['teams'] = [['id' => 610, 'name' => 'Galatasaray SK', 'squad' => []]];
        $this->completeTask($initial, 'teams', $source);
        $snapshotId = $competition->fresh()->active_snapshot_id;
        $job = app(ImportCoordinator::class)->startEntity(Team::firstOrFail(), null);
        Http::fake(['https://provider.test/teams/610' => Http::response($this->galatasaray())]);
        $this->runTask($job);

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame(32, $job->fresh()->quality_summary['players_count']);
        $this->getJson('/api/team/610')->assertOk()
            ->assertJsonCount(32, 'data.team.squad')
            ->assertJsonCount(0, 'data.participations.0.players')
            ->assertJsonPath('data.team.coach.name', 'Okan Buruk')
            ->assertJsonPath('data.import.status', 'completed');
        $this->getJson('/api/players/30283')->assertOk()->assertJsonPath('data.player.name', 'Uğurcan Çakır');
        $this->assertSame($snapshotId, $competition->fresh()->active_snapshot_id);
        $this->assertDatabaseCount('competition_snapshots', 1);
        $this->assertDatabaseCount('snapshot_players', 0);
        $this->assertDatabaseCount('players', 32);
        $this->assertNotNull(Team::first()->source_fetched_at);
        Team::first()->update(['name' => 'Older competition name', 'source_updated_at' => null]);
        $this->getJson('/api/team/610')->assertOk()
            ->assertJsonPath('data.team.name', 'Galatasaray SK')
            ->assertJsonPath('data.team.source_updated_at', '2026-02-25T08:38:42Z');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://provider.test/teams/610');
    }

    public function test_direct_team_refresh_also_accepts_absent_null_and_empty_squads(): void
    {
        $team = $this->team();
        foreach (['absent', 'null', 'empty'] as $kind) {
            $source = $this->galatasaray();
            if ($kind === 'absent') {
                unset($source['squad']);
            } else {
                $source['squad'] = $kind === 'null' ? null : [];
            }
            $job = app(ImportCoordinator::class)->startEntity($team->fresh(), null);
            $this->completeTask($job, 'team_detail', $source);
            $this->assertSame('completed', $job->fresh()->status);
            $this->getJson('/api/team/610')->assertOk()->assertJsonCount(0, 'data.team.squad');
        }
        $this->assertDatabaseCount('players', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_team_refresh_keeps_the_last_valid_profile_and_roster(): void
    {
        $team = $this->team();
        $first = app(ImportCoordinator::class)->startEntity($team, null);
        $this->completeTask($first, 'team_detail', $this->galatasaray());
        $previous = $team->fresh()->source_profile;
        foreach (['id', 'birth', 'duplicate', 'structure'] as $failure) {
            $source = $this->galatasaray();
            $expected = match ($failure) {
                'id' => 'SOURCE_TEAM_MISMATCH',
                'birth' => 'SOURCE_PLAYER_INVALID',
                'duplicate' => 'SOURCE_DUPLICATE_PLAYER',
                default => 'SOURCE_SQUAD_INVALID',
            };
            if ($failure === 'id') {
                $source['id'] = 999;
            }
            if ($failure === 'birth') {
                $source['squad'][0]['dateOfBirth'] = '2026-02-30';
            }
            if ($failure === 'duplicate') {
                $source['squad'][] = $source['squad'][0];
            }
            if ($failure === 'structure') {
                $source['squad'] = 'invalid';
            }
            $job = app(ImportCoordinator::class)->startEntity($team->fresh(), null);
            try {
                $this->completeTask($job, 'team_detail', $source);
                $this->fail('Invalid data must not replace the saved detail.');
            } catch (DomainException $exception) {
                app(ImportCoordinator::class)->failFromException($job, $exception);
            }
            $this->assertSame($expected, $job->fresh()->error_code);
            $this->assertSame($previous, $team->fresh()->source_profile);
            $this->assertDatabaseCount('players', 32);
        }
    }

    public function test_player_refresh_uses_one_person_request_and_retains_participation_values(): void
    {
        $initial = $this->prepareImport();
        $this->completeTask($initial, 'teams', $this->sourceTeams());
        $player = Player::firstOrFail();
        $source = [
            'id' => 4204, 'name' => 'Aynsley Pears', 'dateOfBirth' => '1998-04-23',
            'nationality' => 'England', 'position' => 'Goalkeeper', 'shirtNumber' => 1,
            'lastUpdated' => '2023-07-30T19:41:14Z',
            'currentTeam' => ['id' => 59, 'name' => 'Blackburn Rovers FC', 'venue' => 'Ewood Park', 'contract' => ['start' => '2020-10', 'until' => '2027-06']],
        ];
        $first = $this->postJson('/api/players/4204/refresh')->assertAccepted()->json('data.id');
        $this->postJson('/api/players/4204/refresh')->assertAccepted()->assertJsonPath('data.id', $first);
        Http::fake(['https://provider.test/persons/4204' => Http::response($source)]);
        $this->runTask(Import::findOrFail($first));

        $this->getJson('/api/players/4204')->assertOk()
            ->assertJsonPath('data.player.shirt_number', 1)
            ->assertJsonPath('data.player.current_team.venue', 'Ewood Park')
            ->assertJsonPath('data.player.current_team.contract.until', '2027-06')
            ->assertJsonPath('data.participations.0.shirt_number', null)
            ->assertJsonPath('data.import.status', 'completed');
        $this->assertNotNull($player->fresh()->source_fetched_at);
        $player->update(['name' => 'Older squad name', 'source_updated_at' => null]);
        $this->getJson('/api/players/4204')->assertOk()
            ->assertJsonPath('data.player.name', 'Aynsley Pears')
            ->assertJsonPath('data.player.source_updated_at', '2023-07-30T19:41:14Z');
        $this->assertDatabaseCount('competition_snapshots', 1);
        Http::assertSentCount(1);
    }

    public function test_failed_entity_refresh_can_retry_and_does_not_remove_cached_data(): void
    {
        $team = $this->team();
        $initial = app(ImportCoordinator::class)->startEntity($team, null);
        $this->completeTask($initial, 'team_detail', $this->galatasaray());
        $failed = app(ImportCoordinator::class)->startEntity($team->fresh(), null);
        $providerMessage = 'The resource you are looking for is restricted and apparently not within your permissions. Please check your subscription.';
        $errorMessage = 'Solicitud externa fallida (HTTP 403). '.$providerMessage;
        Http::fake(['*' => Http::response(['message' => $providerMessage, 'errorCode' => 403], 403)]);
        $this->runTask($failed);
        $this->getJson('/api/team/610')->assertOk()
            ->assertJsonPath('data.has_data', true)
            ->assertJsonCount(32, 'data.team.squad')
            ->assertJsonPath('data.import.error_code', 'SOURCE_ACCESS_DENIED')
            ->assertJsonPath('data.import.error_message', $errorMessage);
        $this->getJson('/api/imports/'.$failed->id)->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_code', 'SOURCE_ACCESS_DENIED')
            ->assertJsonPath('data.error_message', $errorMessage);
        $retryId = $this->postJson('/api/imports/'.$failed->id.'/retry')->assertAccepted()->json('data.id');
        $this->assertDatabaseHas('imports', ['id' => $retryId, 'team_id' => $team->id, 'retry_of_import_id' => $failed->id]);
        Http::assertSentCount(1);
    }

    public function test_player_refresh_exposes_provider_error_message_in_both_read_endpoints(): void
    {
        $publishedAt = now()->subDay()->startOfSecond();
        $profile = ['id' => 81187, 'name' => 'Abdelkabir Abqar', 'shirt_number' => 5];
        $player = Player::create(['external_id' => 81187, 'name' => 'Abdelkabir Abqar', 'first_published_at' => $publishedAt, 'source_profile' => $profile]);
        $job = app(ImportCoordinator::class)->startEntity($player, null);
        Http::fake(['https://provider.test/persons/81187' => Http::response(['message' => 'Person details are restricted by your subscription.', 'errorCode' => 403], 403)]);
        $this->runTask($job);
        $message = 'Solicitud externa fallida (HTTP 403). Person details are restricted by your subscription.';

        $this->getJson('/api/players/81187')->assertOk()
            ->assertJsonPath('data.has_data', true)
            ->assertJsonPath('data.player.shirt_number', 5)
            ->assertJsonPath('data.import.error_code', 'SOURCE_ACCESS_DENIED')
            ->assertJsonPath('data.import.error_message', $message);
        $this->getJson('/api/imports/'.$job->id)->assertOk()
            ->assertJsonPath('data.error_message', $message);
        $this->assertSame($profile, $player->fresh()->source_profile);
        $this->assertTrue($publishedAt->equalTo($player->fresh()->first_published_at));
        Http::assertSentCount(1);
    }
}
