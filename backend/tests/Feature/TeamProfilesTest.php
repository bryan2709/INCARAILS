<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\ExternalTask;
use App\Models\Role;
use App\Models\SnapshotTeam;
use App\Models\User;
use App\Services\Football\FootballReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\Support\BuildsCompetitionImports;
use Tests\TestCase;

class TeamProfilesTest extends TestCase
{
    use BuildsCompetitionImports, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->seed();
        $user = User::create(['name' => 'Reader', 'email' => 'teams@test.local', 'password' => 'password-demo-123', 'is_active' => true]);
        $user->roles()->attach(Role::where('code', 'readonly')->firstOrFail());
        Passport::actingAs($user);
    }

    public function test_published_profile_and_squad_demographics_are_exposed_with_external_ids(): void
    {
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->profileSource());
        Queue::fake();

        $this->getJson('/api/team/59')->assertOk()
            ->assertJsonPath('data.team.area.id', 2072)
            ->assertJsonPath('data.team.source_updated_at', '2022-02-19T08:50:27+00:00')
            ->assertJsonPath('data.participations.0.profile.founded', 1874)
            ->assertJsonPath('data.participations.0.profile.venue', 'Ewood Park')
            ->assertJsonPath('data.participations.0.profile.club_colors', 'Blue / White')
            ->assertJsonPath('data.participations.0.profile.coach.name', 'Tony Mowbray')
            ->assertJsonPath('data.participations.0.profile.coach.date_of_birth', '1963-11-22')
            ->assertJsonPath('data.participations.0.profile.coach.contract.until', null)
            ->assertJsonPath('data.participations.0.profile.running_competitions.0.id', 2016)
            ->assertJsonPath('data.participations.0.players.0.id', 4204)
            ->assertJsonPath('data.participations.0.players.0.date_of_birth', '1998-04-23')
            ->assertJsonPath('data.participations.0.players.0.nationality', 'England')
            ->assertJsonMissingPath('data.participations.0.profile.private_field')
            ->assertJsonMissingPath('data.participations.0.profile.coach.private_field');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_saved_source_backfills_legacy_metadata_without_changing_rosters_and_is_idempotent(): void
    {
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->profileSource());
        $team = SnapshotTeam::firstOrFail();
        $team->update(['metadata' => ['tla' => 'BLA']]);
        $snapshotId = $import->competition->fresh()->active_snapshot_id;
        Queue::fake();

        $this->artisan('football:backfill-team-profiles', ['--competition' => 2016])
            ->expectsOutput('Perfiles actualizados: 1. Snapshots omitidos: 0.')->assertSuccessful();
        $this->assertSame('Ewood Park', $team->fresh()->metadata['venue']);
        $this->assertSame($snapshotId, $import->competition->fresh()->active_snapshot_id);
        $this->assertDatabaseCount('competition_snapshots', 1);
        $this->assertDatabaseCount('snapshot_players', 1);
        $this->artisan('football:backfill-team-profiles')
            ->expectsOutput('Perfiles actualizados: 0. Snapshots omitidos: 0.')->assertSuccessful();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_missing_saved_payload_is_skipped_without_altering_published_data(): void
    {
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->profileSource());
        $team = SnapshotTeam::firstOrFail();
        $team->update(['metadata' => ['tla' => 'BLA']]);
        ExternalTask::whereHas('step', fn ($query) => $query->where('import_id', $import->id)->where('step_key', 'teams'))
            ->update(['response_json' => null]);

        $this->artisan('football:backfill-team-profiles')
            ->expectsOutput('Perfiles actualizados: 0. Snapshots omitidos: 1.')->assertSuccessful();
        $this->assertSame(['tla' => 'BLA'], $team->fresh()->metadata);
        Http::assertNothingSent();
    }

    public function test_a_saved_payload_for_another_season_cannot_enrich_the_snapshot(): void
    {
        $import = $this->prepareImport();
        $source = $this->profileSource();
        $this->completeTask($import, 'teams', $source);
        $team = SnapshotTeam::firstOrFail();
        $team->update(['metadata' => ['tla' => 'BLA']]);
        $source['season']['id'] = 9999;
        ExternalTask::whereHas('step', fn ($query) => $query->where('import_id', $import->id)->where('step_key', 'teams'))->firstOrFail()
            ->update(['response_json' => $source]);

        $this->artisan('football:backfill-team-profiles')
            ->expectsOutput('Snapshot #'.$team->snapshot_id.': SOURCE_SEASON_MISMATCH')
            ->expectsOutput('Perfiles actualizados: 0. Snapshots omitidos: 1.')->assertSuccessful();
        $this->assertSame(['tla' => 'BLA'], $team->fresh()->metadata);
    }

    public function test_backfill_only_touches_active_snapshots_and_keeps_retired_profiles(): void
    {
        $import = $this->prepareImport();
        $source = $this->profileSource();
        $source['teams'][0]['venue'] = 'Previous venue';
        $this->completeTask($import, 'teams', $source);
        $previousTeam = SnapshotTeam::firstOrFail();
        $refresh = $this->prepareImport($import->competition->fresh());
        $this->completeTask($refresh, 'teams', $this->profileSource());
        $currentTeam = SnapshotTeam::where('snapshot_id', $import->competition->fresh()->active_snapshot_id)->firstOrFail();
        $currentTeam->update(['metadata' => ['tla' => 'BLA']]);

        $this->artisan('football:backfill-team-profiles')
            ->expectsOutput('Perfiles actualizados: 1. Snapshots omitidos: 0.')->assertSuccessful();
        $this->assertSame('Previous venue', $previousTeam->fresh()->metadata['venue']);
        $this->assertSame('Ewood Park', $currentTeam->fresh()->metadata['venue']);
        $this->getJson('/api/team/59')->assertOk()->assertJsonCount(1, 'data.participations')
            ->assertJsonPath('data.participations.0.profile.venue', 'Ewood Park');
    }

    public function test_team_query_count_is_constant_with_multiple_participations(): void
    {
        $read = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                app(FootballReadService::class)->team(59);

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->profileSource());
        $single = $read();
        foreach ([2013, 2021] as $externalId) {
            $competition = Competition::create(['external_id' => $externalId, 'name' => 'League '.$externalId]);
            $import = $this->prepareImport($competition);
            $this->completeTask($import, 'teams', $this->profileSource($externalId));
        }

        $this->assertSame($single, $read());
        // Includes one query for the latest individual refresh status.
        $this->assertLessThanOrEqual(9, $single);
    }

    private function profileSource(int $competitionId = 2016): array
    {
        $source = $this->sourceTeams($competitionId);
        $source['teams'][0] += [
            'area' => ['id' => 2072, 'name' => 'England', 'code' => 'ENG', 'flag' => 'https://crests.football-data.org/770.svg'],
            'shortName' => 'Blackburn',
            'tla' => 'BLA',
            'crest' => 'https://crests.football-data.org/59.png',
            'address' => 'Ewood Park Blackburn BB2 4JF',
            'website' => 'http://www.rovers.co.uk',
            'founded' => 1874,
            'clubColors' => 'Blue / White',
            'venue' => 'Ewood Park',
            'coach' => ['id' => 14572, 'name' => 'Tony Mowbray', 'dateOfBirth' => '1963-11-22', 'nationality' => 'England', 'contract' => ['start' => null, 'until' => null], 'private_field' => 'ignored'],
            'runningCompetitions' => [['id' => 2016, 'name' => 'Championship', 'type' => 'LEAGUE']],
            'private_field' => 'ignored',
        ];
        $source['teams'][0]['squad'][0] += ['dateOfBirth' => '1998-04-23', 'nationality' => 'England'];

        return $source;
    }
}
