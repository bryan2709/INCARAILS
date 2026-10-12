<?php

namespace Tests\Feature;

use App\Exceptions\InvalidSquadException;
use App\Models\Competition;
use App\Models\CompetitionSeason;
use App\Models\Player;
use App\Models\Role;
use App\Models\SnapshotPlayer;
use App\Models\Team;
use App\Models\User;
use App\Services\Football\CompetitionDetails;
use App\Services\Football\FootballReadService;
use App\Services\ImportCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\Support\BuildsCompetitionImports;
use Tests\TestCase;

class FootballReadTest extends TestCase
{
    use BuildsCompetitionImports, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->seed();
        $user = User::create(['name' => 'Reader', 'email' => 'reader@test.local', 'password' => 'password-demo-123', 'is_active' => true]);
        $user->roles()->attach(Role::where('code', 'readonly')->firstOrFail());
        Passport::actingAs($user);
    }

    public function test_readonly_empty_catalog_does_not_start_imports(): void
    {
        $this->getJson('/api/competitions')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('imports', 0);
        Queue::assertNothingPushed();
    }

    public function test_catalog_can_be_filtered_by_type(): void
    {
        Competition::create(['external_id' => 1, 'name' => 'League', 'type' => 'LEAGUE', 'catalog_fetched_at' => now()]);
        Competition::create(['external_id' => 2, 'name' => 'Cup', 'type' => 'CUP', 'catalog_fetched_at' => now()]);

        $this->getJson('/api/competitions?type=CUP')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', 2);
    }

    public function test_invalid_pagination_and_type_have_structured_validation_errors(): void
    {
        foreach (['per_page=101', 'page=0', 'search[]=bad', 'type=OTHER'] as $query) {
            $this->getJson('/api/competitions?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }
    }

    public function test_invalid_external_id_returns_404(): void
    {
        $this->getJson('/api/team/not-a-number')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_published_data_survives_provider_outage_and_failed_refresh(): void
    {
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->sourceTeams());
        $refresh = app(ImportCoordinator::class)->start($import->competition->fresh(), null, 'refresh');
        app(ImportCoordinator::class)->fail($refresh, 'SOURCE_UNAVAILABLE', 'Provider offline');

        $this->getJson('/api/competitions/2016')->assertOk()
            ->assertJsonPath('data.competition.snapshot_id', $import->competition->fresh()->active_snapshot_id)
            ->assertJsonPath('data.import.error_message', 'Provider offline')
            ->assertJsonPath('data.snapshot.teams.0.id', 59)
            ->assertJsonPath('data.snapshot.teams.0.players.0.id', 4204);
        $this->getJson('/api/team/59')->assertOk()->assertJsonPath('data.participations.0.players.0.id', 4204);
        $this->getJson('/api/players/4204')->assertOk()->assertJsonPath('data.participations.0.position', 'Goalkeeper');
        Http::assertNothingSent();
    }

    public function test_competition_detail_returns_published_teams_and_squads_with_external_ids(): void
    {
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->sourceTeams());
        Queue::fake();

        $this->getJson('/api/competitions/2016')->assertOk()
            ->assertJsonPath('data.snapshot.id', $import->competition->fresh()->active_snapshot_id)
            ->assertJsonPath('data.snapshot.season.id', 2509)
            ->assertJsonPath('data.snapshot.teams_count', 1)
            ->assertJsonPath('data.snapshot.players_count', 1)
            ->assertJsonPath('data.snapshot.teams.0.id', 59)
            ->assertJsonPath('data.snapshot.teams.0.name', 'Blackburn Rovers FC')
            ->assertJsonPath('data.snapshot.teams.0.players.0.id', 4204)
            ->assertJsonPath('data.snapshot.teams.0.players.0.name', 'Aynsley Pears')
            ->assertJsonPath('data.snapshot.teams.0.players.0.position', 'Goalkeeper')
            ->assertJsonPath('data.snapshot.teams.0.players.0.shirt_number', null);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_unpublished_competition_has_no_snapshot_and_readonly_does_not_import(): void
    {
        Competition::create(['external_id' => 2013, 'name' => 'League']);

        $this->getJson('/api/competitions/2013')->assertOk()
            ->assertJsonPath('data.snapshot', null)
            ->assertJsonPath('data.competition.snapshot_id', null);

        $this->assertDatabaseCount('imports', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_failed_squad_diagnostics_are_available_on_competition_reload_and_import_detail(): void
    {
        $import = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['teams'][0]['squad'] = 'invalid';
        try {
            $this->completeTask($import, 'teams', $source);
            $this->fail('A malformed squad must fail validation.');
        } catch (InvalidSquadException $exception) {
            app(ImportCoordinator::class)->failFromException($import, $exception);
        }
        $import->refresh();

        $this->getJson('/api/competitions/2016')->assertOk()
            ->assertJsonPath('data.snapshot', null)
            ->assertJsonPath('data.import.status', 'failed')
            ->assertJsonPath('data.import.error_code', 'SOURCE_SQUAD_INVALID')
            ->assertJsonPath('data.import.error_message', $import->error_message)
            ->assertJsonPath('data.import.quality_summary.invalid_squads_count', 1)
            ->assertJsonPath('data.import.quality_summary.teams_with_incomplete_squads.0.id', 59);
        $this->getJson('/api/imports/'.$import->id)->assertOk()
            ->assertJsonPath('data.error_message', $import->error_message)
            ->assertJsonPath('data.quality_summary', $import->quality_summary);
        $this->assertDatabaseCount('imports', 1);
        Http::assertNothingSent();
    }

    public function test_published_teams_without_squads_remain_readable_with_quality_summary(): void
    {
        $import = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['teams'][0]['venue'] = 'Ewood Park';
        unset($source['teams'][0]['squad']);
        $source['teams'][] = ['id' => 60, 'name' => 'Club without players', 'squad' => null];
        $source['teams'][] = ['id' => 61, 'name' => 'Club with empty list', 'squad' => []];
        $source['count'] = 3;
        $this->completeTask($import, 'teams', $source);

        $this->getJson('/api/competitions/2016')->assertOk()
            ->assertJsonPath('data.import.status', 'completed')
            ->assertJsonPath('data.import.error_code', null)
            ->assertJsonPath('data.snapshot.teams_count', 3)
            ->assertJsonPath('data.snapshot.players_count', 0)
            ->assertJsonPath('data.snapshot.quality_summary.missing_squads_count', 2)
            ->assertJsonPath('data.snapshot.quality_summary.empty_squads_count', 1)
            ->assertJsonCount(0, 'data.snapshot.teams.0.players');
        foreach ([59, 60, 61] as $id) {
            $this->getJson('/api/team/'.$id)->assertOk()
                ->assertJsonPath('data.team.id', $id)
                ->assertJsonCount(1, 'data.participations')
                ->assertJsonCount(0, 'data.participations.0.players');
        }
        $this->getJson('/api/team/59')->assertOk()->assertJsonPath('data.participations.0.profile.venue', 'Ewood Park');
        $this->getJson('/api/team')->assertOk()->assertJsonPath('meta.total', 3);
        Http::assertNothingSent();
    }

    public function test_competition_keeps_snapshot_names_and_published_season_when_identities_change(): void
    {
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->sourceTeams());
        Team::where('external_id', 59)->update(['name' => 'New identity name']);
        Player::where('external_id', 4204)->update(['name' => 'New player name']);
        $season = CompetitionSeason::create(['competition_id' => $import->competition_id, 'external_id' => 2510, 'start_date' => '2027-08-01']);
        $import->competition->update(['current_season_id' => $season->id]);

        $this->getJson('/api/competitions/2016')->assertOk()
            ->assertJsonPath('data.competition.needs_refresh', true)
            ->assertJsonPath('data.competition.season.id', 2510)
            ->assertJsonPath('data.snapshot.season.id', 2509)
            ->assertJsonPath('data.snapshot.teams.0.name', 'Blackburn Rovers FC')
            ->assertJsonPath('data.snapshot.teams.0.players.0.name', 'Aynsley Pears');
    }

    public function test_competition_query_count_does_not_grow_with_teams_and_players(): void
    {
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->sourceTeams());
        $user = User::where('email', 'reader@test.local')->firstOrFail();
        $read = function () use ($user): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $result = app(CompetitionDetails::class)->get(2016, $user);

                return [$result['data']['snapshot'], count(DB::getQueryLog())];
            } finally {
                DB::disableQueryLog();
            }
        };
        [, $singleTeamQueries] = $read();
        $refresh = $this->prepareImport($import->competition->fresh());
        $source = $this->sourceTeams();
        $source['count'] = 24;
        $source['teams'] = [];
        for ($index = 0; $index < 24; $index++) {
            $source['teams'][] = ['id' => 59 + $index, 'name' => 'Team '.$index, 'squad' => [
                ['id' => 4204 + $index, 'name' => 'Player '.$index],
            ]];
        }
        $this->completeTask($refresh, 'teams', $source);
        [$snapshot, $multipleTeamQueries] = $read();

        $this->assertCount(24, $snapshot['teams']);
        $this->assertSame($singleTeamQueries, $multipleTeamQueries);
        $this->assertLessThanOrEqual(10, $multipleTeamQueries);
    }

    public function test_retired_participations_are_hidden_but_historical_identities_remain_listed(): void
    {
        $import = $this->prepareImport();
        $this->completeTask($import, 'teams', $this->sourceTeams());
        $refresh = $this->prepareImport($import->competition->fresh());
        $source = $this->sourceTeams();
        $source['teams'][0]['id'] = 60;
        $source['teams'][0]['squad'][0]['id'] = 4205;
        $this->completeTask($refresh, 'teams', $source);

        $this->getJson('/api/team')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/players')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/team/59')->assertOk()->assertJsonCount(0, 'data.participations');
        $this->getJson('/api/players/4204')->assertOk()->assertJsonCount(0, 'data.participations');
        $snapshot = $refresh->competition->fresh()->activeSnapshot;
        $this->getJson('/api/team/60')->assertOk()
            ->assertJsonPath('data.participations.0.snapshot.id', $snapshot->id)
            ->assertJsonPath('data.participations.0.snapshot.published_at', $snapshot->published_at->toIso8601String());
        $this->getJson('/api/players/4205')->assertOk()
            ->assertJsonPath('data.participations.0.snapshot.id', $snapshot->id)
            ->assertJsonPath('data.participations.0.snapshot.published_at', $snapshot->published_at->toIso8601String());
        $this->getJson('/api/competitions/2016')->assertOk()
            ->assertJsonCount(1, 'data.snapshot.teams')
            ->assertJsonPath('data.snapshot.teams.0.id', 60)
            ->assertJsonPath('data.snapshot.teams.0.players.0.id', 4205);
    }

    public function test_detail_queries_do_not_grow_with_participation_count(): void
    {
        $counts = [];
        $data = null;
        foreach ([2016, 2013, 2021] as $externalId) {
            $competition = Competition::create(['external_id' => $externalId, 'name' => 'League '.$externalId]);
            $import = $this->prepareImport($competition);
            $this->completeTask($import, 'teams', $this->sourceTeams($externalId));
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $data = app(FootballReadService::class)->player(4204);
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        }

        $this->assertCount(3, $data['participations']);
        $this->assertSame([$counts[0], $counts[0], $counts[0]], $counts);
        // Includes one query for the latest individual refresh status.
        $this->assertLessThanOrEqual(8, $counts[0]);
    }

    public function test_player_profile_exposes_optional_identity_fields_and_published_team_details(): void
    {
        $import = $this->prepareImport();
        $import->competition->update(['code' => 'ELC', 'emblem_url' => 'https://crests.football-data.org/ELC.png']);
        $source = $this->sourceTeams();
        $source['teams'][0]['crest'] = 'https://crests.football-data.org/59.png';
        $source['teams'][0]['tla'] = 'BLA';
        $source['teams'][0]['shortName'] = 'Blackburn';
        $source['teams'][0]['squad'][0] += [
            'firstName' => 'Aynsley',
            'lastName' => 'Pears',
            'dateOfBirth' => '1998-04-23',
            'nationality' => 'England',
            'shirtNumber' => 1,
            'lastUpdated' => '2023-07-30T14:41:14-05:00',
        ];
        $this->completeTask($import, 'teams', $source);
        Queue::fake();

        $this->getJson('/api/players/4204')->assertOk()
            ->assertJsonPath('data.player.first_name', 'Aynsley')
            ->assertJsonPath('data.player.last_name', 'Pears')
            ->assertJsonPath('data.player.date_of_birth', '1998-04-23')
            ->assertJsonPath('data.player.nationality', 'England')
            ->assertJsonPath('data.player.source_updated_at', '2023-07-30T19:41:14+00:00')
            ->assertJsonPath('data.participations.0.team.id', 59)
            ->assertJsonPath('data.participations.0.team.tla', 'BLA')
            ->assertJsonPath('data.participations.0.team.crest', 'https://crests.football-data.org/59.png')
            ->assertJsonPath('data.participations.0.competition.emblem', 'https://crests.football-data.org/ELC.png')
            ->assertJsonPath('data.participations.0.season.end_date', '2027-05-01')
            ->assertJsonPath('data.participations.0.shirt_number', 1);

        $this->assertDatabaseHas('players', ['external_id' => 4204, 'source_updated_at' => '2023-07-30 19:41:14']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_shirt_number_is_persisted_and_returned_by_team_player_and_competition_details(): void
    {
        $import = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['teams'][0]['squad'] = [
            ['id' => 4204, 'name' => 'Player 4204', 'shirtNumber' => 1],
            ['id' => 4205, 'name' => 'Player 4205', 'shirtNumber' => 0],
            ['id' => 4206, 'name' => 'Player 4206', 'shirtNumber' => null],
            ['id' => 4207, 'name' => 'Player 4207'],
            ['id' => 4208, 'name' => 'Player 4208', 'shirtNumber' => '7'],
        ];
        $this->completeTask($import, 'teams', $source);
        Queue::fake();
        $team = collect($this->getJson('/api/team/59')->assertOk()->json('data.participations.0.players'))->keyBy('id');
        $competition = collect($this->getJson('/api/competitions/2016')->assertOk()->json('data.snapshot.teams.0.players'))->keyBy('id');

        foreach ([4204 => 1, 4205 => 0, 4206 => null, 4207 => null, 4208 => 7] as $externalId => $expected) {
            $identity = Player::where('external_id', $externalId)->firstOrFail();
            $this->assertDatabaseHas('snapshot_players', ['player_id' => $identity->id, 'shirt_number' => $expected]);
            $this->assertSame($expected, $team[$externalId]['shirt_number']);
            $this->assertSame($expected, $competition[$externalId]['shirt_number']);
            $this->getJson('/api/players/'.$externalId)->assertOk()->assertJsonPath('data.participations.0.shirt_number', $expected);
        }
        $this->assertSame(2, $import->fresh()->quality_summary['players_missing_shirt_number']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_shirt_numbers_follow_their_snapshot_and_competition_when_refreshing(): void
    {
        $initial = $this->prepareImport();
        $source = $this->sourceTeams();
        $source['teams'][0]['squad'][0]['shirtNumber'] = 1;
        $this->completeTask($initial, 'teams', $source);
        $oldSnapshot = $initial->competition->fresh()->active_snapshot_id;
        $other = $this->prepareImport(Competition::create(['external_id' => 2013, 'name' => 'Other league']));
        $otherSource = $this->sourceTeams(2013);
        $otherSource['teams'][0]['squad'][0]['shirtNumber'] = 22;
        $this->completeTask($other, 'teams', $otherSource);
        $refresh = $this->prepareImport($initial->competition->fresh());
        $source['teams'][0]['squad'][0]['shirtNumber'] = 10;
        $this->completeTask($refresh, 'teams', $source);

        $participations = collect($this->getJson('/api/players/4204')->assertOk()->json('data.participations'))->keyBy('competition.id');
        $this->assertSame(10, $participations[2016]['shirt_number']);
        $this->assertSame(22, $participations[2013]['shirt_number']);
        $this->assertSame(1, SnapshotPlayer::whereHas('snapshotTeam', fn ($query) => $query->where('snapshot_id', $oldSnapshot))->firstOrFail()->shirt_number);

        $withoutNumber = $this->prepareImport($initial->competition->fresh());
        $this->completeTask($withoutNumber, 'teams', $this->sourceTeams());
        $participations = collect($this->getJson('/api/players/4204')->assertOk()->json('data.participations'))->keyBy('competition.id');
        $this->assertNull($participations[2016]['shirt_number']);
        $this->assertSame(22, $participations[2013]['shirt_number']);
    }
}
