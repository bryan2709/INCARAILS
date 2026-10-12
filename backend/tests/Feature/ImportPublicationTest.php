<?php

namespace Tests\Feature;

use App\Exceptions\InvalidSquadException;
use App\Models\Competition;
use App\Models\CompetitionSeason;
use App\Models\CompetitionSnapshot;
use App\Models\Team;
use App\Services\ImportCoordinator;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsCompetitionImports;
use Tests\TestCase;

class ImportPublicationTest extends TestCase
{
    use BuildsCompetitionImports, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_publication_of_24_teams_and_677_players(): void
    {
        $import = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['count'] = 24;
        $source['teams'] = [];
        $playerId = 1;
        for ($team = 1; $team <= 24; $team++) {
            $squad = [];
            for ($player = 0; $player < ($team === 1 ? 33 : 28); $player++) {
                $squad[] = ['id' => $playerId, 'name' => 'Player '.$playerId, 'position' => $playerId <= 3 ? null : 'Midfield'];
                $playerId++;
            }
            $source['teams'][] = ['id' => $team, 'name' => 'Team '.$team, 'squad' => $squad];
        }
        $this->completeTask($import, 'teams', $source);

        $this->assertSame('completed', $import->fresh()->status);
        $this->assertDatabaseCount('teams', 24);
        $this->assertDatabaseCount('players', 677);
        $this->assertDatabaseCount('snapshot_players', 677);
        $this->assertSame(3, $import->fresh()->quality_summary['players_missing_position']);
        $this->assertSame(677, $import->fresh()->quality_summary['players_missing_shirt_number']);
    }

    public function test_failed_refresh_preserves_previous_snapshot_and_identities(): void
    {
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->sourceTeams());
        $competition = $import->competition->fresh();
        $snapshotId = $competition->active_snapshot_id;
        $refresh = $this->prepareImport($competition);
        $source = $this->sourceTeams();
        $source['teams'][0]['name'] = 'Invalid refresh';
        $source['teams'][0]['squad'] = 'invalid';
        try {
            $this->completeTask($refresh, 'teams', $source);
            $this->fail('Malformed squads must fail validation.');
        } catch (DomainException $exception) {
            app(ImportCoordinator::class)->failFromException($refresh, $exception);
        }

        $this->assertSame($snapshotId, $competition->fresh()->active_snapshot_id);
        $this->assertSame('Blackburn Rovers FC', Team::first()->name);
        $this->assertSame('failed', $refresh->fresh()->status);
        $this->assertSame('SOURCE_SQUAD_INVALID', $refresh->fresh()->error_code);
        $this->assertSame(1, $refresh->fresh()->quality_summary['invalid_squads_count']);
        $this->assertStringContainsString('Invalid refresh', $refresh->fresh()->error_message);
        $this->assertDatabaseCount('competition_snapshots', 1);
    }

    public function test_champions_league_publishes_all_36_teams_with_34_empty_squads_and_7_players(): void
    {
        $competition = Competition::create(['external_id' => 2001, 'name' => 'UEFA Champions League']);
        $import = $this->prepareImport($competition, 2557);
        $source = $this->sourceTeams(2001, 2557);
        $source['count'] = 36;
        $source['teams'] = [];
        for ($id = 1; $id <= 36; $id++) {
            $squad = [];
            if ($id <= 2) {
                for ($player = 1; $player <= ($id === 1 ? 3 : 4); $player++) {
                    $squad[] = ['id' => $id * 100 + $player, 'name' => 'Player '.$id.'-'.$player];
                }
            }
            $source['teams'][] = ['id' => $id, 'name' => 'Club '.$id, 'squad' => $squad];
        }

        $this->completeTask($import, 'teams', $source);

        $import->refresh();
        $this->assertSame('completed', $import->status);
        $this->assertNull($import->error_code);
        $this->assertNull($import->error_message);
        $this->assertSame(36, $import->quality_summary['expected_teams']);
        $this->assertSame(36, $import->quality_summary['received_teams']);
        $this->assertSame(7, $import->quality_summary['players_count']);
        $this->assertSame(34, $import->quality_summary['empty_squads_count']);
        $this->assertSame(0, $import->quality_summary['missing_squads_count']);
        $this->assertSame(2557, $import->quality_summary['source_season_id']);
        $this->assertCount(34, $import->quality_summary['teams_with_incomplete_squads']);
        $this->assertSame(['id' => 3, 'name' => 'Club 3', 'reason' => 'empty'], $import->quality_summary['teams_with_incomplete_squads'][0]);
        $snapshot = CompetitionSnapshot::findOrFail($competition->fresh()->active_snapshot_id);
        $this->assertSame('published', $snapshot->build_status);
        $this->assertSame($import->quality_summary, $snapshot->quality_summary);
        $this->assertNull($import->active_scope_key);
        $this->assertDatabaseCount('teams', 36);
        $this->assertDatabaseCount('snapshot_teams', 36);
        $this->assertDatabaseCount('players', 7);
        $this->assertDatabaseCount('snapshot_players', 7);
        $this->assertDatabaseCount('competition_snapshots', 1);
    }

    public function test_missing_null_and_empty_squads_publish_teams_even_without_any_players(): void
    {
        $import = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['teams'] = [
            ['id' => 59, 'name' => 'Missing squad', 'venue' => 'Local stadium'],
            ['id' => 60, 'name' => 'Null squad', 'squad' => null],
            ['id' => 61, 'name' => 'Empty squad', 'squad' => []],
        ];
        $source['count'] = 3;
        $this->completeTask($import, 'teams', $source);
        $this->completeTask($import, 'teams', $source);

        $import->refresh();
        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->quality_summary['missing_squads_count']);
        $this->assertSame(1, $import->quality_summary['empty_squads_count']);
        $this->assertSame(0, $import->quality_summary['players_count']);
        $this->assertSame(0, $import->quality_summary['players_missing_position']);
        $this->assertSame(0, $import->quality_summary['players_missing_shirt_number']);
        $this->assertDatabaseCount('teams', 3);
        $this->assertDatabaseCount('snapshot_teams', 3);
        $this->assertDatabaseCount('players', 0);
        $this->assertDatabaseCount('snapshot_players', 0);
        $this->assertDatabaseCount('competition_snapshots', 1);
        $this->assertSame('Local stadium', CompetitionSnapshot::first()->teams()->where('name', 'Missing squad')->first()->metadata['venue']);
        $this->assertDatabaseMissing('teams', ['first_published_at' => null]);
    }

    public function test_refresh_without_a_squad_publishes_no_players_and_preserves_historical_roster(): void
    {
        $initial = $this->prepareImport();
        $this->completeTask($initial, 'teams', $this->sourceTeams());
        $competition = $initial->competition->fresh();
        $oldSnapshotId = $competition->active_snapshot_id;
        $refresh = $this->prepareImport($competition);
        $source = $this->sourceTeams();
        unset($source['teams'][0]['squad']);
        $this->completeTask($refresh, 'teams', $source);

        $newSnapshot = CompetitionSnapshot::findOrFail($competition->fresh()->active_snapshot_id);
        $oldSnapshot = CompetitionSnapshot::findOrFail($oldSnapshotId);
        $this->assertSame('completed', $refresh->fresh()->status);
        $this->assertNotSame($oldSnapshotId, $newSnapshot->id);
        $this->assertNotNull($oldSnapshot->retired_at);
        $this->assertSame(1, $oldSnapshot->teams()->first()->players()->count());
        $this->assertSame(0, $newSnapshot->teams()->first()->players()->count());
        $this->assertSame(1, $newSnapshot->quality_summary['missing_squads_count']);
        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseCount('snapshot_players', 1);
    }

    public function test_squad_diagnostics_distinguish_missing_empty_and_invalid_lists(): void
    {
        $import = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['teams'] = [
            ...$source['teams'],
            ['id' => 60, 'name' => 'Missing squad'],
            ['id' => 61, 'name' => 'Null squad', 'squad' => null],
            ['id' => 62, 'name' => 'Empty squad', 'squad' => []],
            ['id' => 63, 'name' => 'Invalid squad', 'squad' => 'unknown'],
            ['id' => 64, 'name' => 'Invalid object', 'squad' => ['players' => []]],
        ];
        $source['count'] = count($source['teams']);

        try {
            $this->completeTask($import, 'teams', $source);
            $this->fail('Malformed squads must prevent publication.');
        } catch (InvalidSquadException $exception) {
            app(ImportCoordinator::class)->failFromException($import, $exception);
        }

        $quality = $import->fresh()->quality_summary;
        $this->assertSame('SOURCE_SQUAD_INVALID', $import->fresh()->error_code);
        $this->assertSame(2, $quality['missing_squads_count']);
        $this->assertSame(1, $quality['empty_squads_count']);
        $this->assertSame(2, $quality['invalid_squads_count']);
        $this->assertSame(1, $quality['players_count']);
        $this->assertSame(['missing', 'missing', 'empty', 'invalid', 'invalid'], array_column($quality['teams_with_incomplete_squads'], 'reason'));
        $this->assertDatabaseCount('competition_snapshots', 0);
    }

    public function test_duplicate_player_across_teams_is_rejected_before_persistence(): void
    {
        $import = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['count'] = 2;
        $source['teams'][] = $source['teams'][0];
        $source['teams'][1]['id'] = 60;

        try {
            $this->completeTask($import, 'teams', $source);
            $this->fail('Duplicated player must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('SOURCE_DUPLICATE_PLAYER', $exception->getMessage());
        }
        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('players', 0);
        $this->assertDatabaseCount('competition_snapshots', 0);
    }

    public function test_season_changed_during_import_cannot_be_published(): void
    {
        $import = $this->prepareImport();
        $other = CompetitionSeason::create(['competition_id' => $import->competition_id, 'external_id' => 9999, 'start_date' => '2027-08-01']);
        Competition::whereKey($import->competition_id)->update(['current_season_id' => $other->id]);
        try {
            $this->completeTask($import, 'teams', $this->sourceTeams());
            $this->fail('Outdated season must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('SOURCE_SEASON_OUTDATED', $exception->getMessage());
        }
        $this->assertDatabaseCount('competition_snapshots', 0);
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_timestamps_with_offset_are_stored_in_utc(): void
    {
        $import = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['teams'][0]['lastUpdated'] = '2022-02-19T03:50:27-05:00';
        $this->completeTask($import, 'teams', $source);

        $this->assertDatabaseHas('teams', ['source_updated_at' => '2022-02-19 08:50:27']);
    }

    public function test_replaying_completed_response_does_not_publish_twice(): void
    {
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->sourceTeams());
        $this->completeTask($import, 'teams', $this->sourceTeams());

        $this->assertDatabaseCount('competition_snapshots', 1);
        $this->assertDatabaseCount('snapshot_teams', 1);
        $this->assertDatabaseCount('snapshot_players', 1);
        $this->assertSame('published', CompetitionSnapshot::first()->build_status);
    }

    public function test_invalid_player_timestamp_cannot_reach_database_publication(): void
    {
        $import = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['teams'][0]['squad'][0]['lastUpdated'] = 'invalid timestamp';
        try {
            $this->completeTask($import, 'teams', $source);
            $this->fail('Invalid player timestamps must be rejected before writing identities.');
        } catch (DomainException $exception) {
            $this->assertSame('SOURCE_TIMESTAMP_INVALID', $exception->getMessage());
        }

        $this->assertDatabaseCount('players', 0);
        $this->assertDatabaseCount('competition_snapshots', 0);
    }

    public function test_invalid_shirt_number_does_not_replace_a_published_number(): void
    {
        $import = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['teams'][0]['squad'][0]['shirtNumber'] = 1;
        $this->completeTask($import, 'teams', $source);
        $snapshotId = $import->competition->fresh()->active_snapshot_id;
        $refresh = $this->prepareImport($import->competition->fresh());
        $source['teams'][0]['squad'][0]['shirtNumber'] = -1;

        try {
            $this->completeTask($refresh, 'teams', $source);
            $this->fail('An invalid shirt number must fail source validation.');
        } catch (DomainException $exception) {
            $this->assertSame('SOURCE_PLAYER_INVALID', $exception->getMessage());
            app(ImportCoordinator::class)->failFromException($refresh, $exception);
        }

        $this->assertSame($snapshotId, $import->competition->fresh()->active_snapshot_id);
        $this->assertDatabaseCount('competition_snapshots', 1);
        $this->assertDatabaseHas('snapshot_players', ['shirt_number' => 1]);
    }

    public function test_internal_exception_is_not_exposed_in_import_error(): void
    {
        $import = $this->prepareImport();
        app(ImportCoordinator::class)->failFromException($import, new \RuntimeException('SQL: private payload'));

        $this->assertSame('SOURCE_VALIDATION_ERROR', $import->fresh()->error_code);
        $this->assertStringNotContainsString('SQL', $import->fresh()->error_message);
    }

    public function test_database_failure_rolls_back_all_publication_writes(): void
    {
        $import = $this->prepareImport();
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'snapshot_players')) {
                throw new \RuntimeException('Simulated storage failure');
            }
        });

        try {
            $this->completeTask($import, 'teams', $this->sourceTeams());
            $this->fail('The simulated write failure must abort publication.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated storage failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('players', 0);
        $this->assertDatabaseCount('competition_snapshots', 0);
        $this->assertNull($import->competition->fresh()->active_snapshot_id);
    }
}
