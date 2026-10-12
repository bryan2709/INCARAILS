<?php

namespace App\Services\Imports;

use App\Models\Competition;
use App\Models\CompetitionSeason;
use App\Models\CompetitionSnapshot;
use App\Models\Import;
use App\Models\Player;
use App\Models\SnapshotPlayer;
use App\Models\SnapshotTeam;
use App\Models\Team;
use DomainException;
use Illuminate\Support\Facades\DB;

class SnapshotPublisher
{
    public function __construct(private SourceCatalogMapper $mapper, private SourcePayloadValidator $validator, private TeamProfileMapper $profiles) {}

    public function publish(Import $import, Competition $competition, CompetitionSeason $season, array $teams, array $quality): void
    {
        DB::transaction(function () use ($import, $competition, $season, $teams, $quality): void {
            // Use the same lock order as ImportCoordinator: competition, then import.
            $competition = Competition::whereKey($competition->id)->lockForUpdate()->firstOrFail();
            $import = Import::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if (in_array($import->status, [
                'failed',
                'completed',
            ], true)) {
                return;
            }

            if ($competition->current_season_id !== $season->id || $season->competition_id !== $competition->id || $import->active_scope_key !== 'competition:'.$competition->id) {
                throw new DomainException('SOURCE_SEASON_OUTDATED');
            }

            $snapshot = CompetitionSnapshot::firstOrCreate([
                'import_id' => $import->id,
            ], [
                'competition_id' => $competition->id,
                'season_id' => $season->id,
                'build_status' => 'staging',
                'quality_summary' => $quality,
            ]);
            $this->persistTeams($snapshot, $teams);

            if ($competition->active_snapshot_id) {
                CompetitionSnapshot::whereKey($competition->active_snapshot_id)->update([
                    'retired_at' => now(),
                ]);
            }

            $snapshot->update([
                'build_status' => 'published',
                'published_at' => now(),
                'quality_summary' => $quality,
            ]);
            $competition->update([
                'active_snapshot_id' => $snapshot->id,
            ]);
            $import->update([
                'status' => 'completed',
                'active_scope_key' => null,
                'completed_at' => now(),
                'quality_summary' => $quality,
            ]);
        }, 3);
    }

    private function persistTeams(CompetitionSnapshot $snapshot, array $teams): void
    {
        $teamRows = [];
        $playerRows = [];
        $areaIds = [];
        $publishedAt = now();

        foreach ($teams as $team) {
            $area = $team['area'] ?? null;
            $areaKey = $area['id'] ?? 'none';
            if (! array_key_exists($areaKey, $areaIds)) {
                $areaIds[$areaKey] = $this->mapper->area($area);
            }

            $teamRows[] = [
                'external_id' => $team['id'],
                'area_id' => $areaIds[$areaKey],
                'name' => $team['name'],
                'short_name' => $team['shortName'] ?? null,
                'tla' => $team['tla'] ?? null,
                'crest_url' => $team['crest'] ?? null,
                'source_updated_at' => $this->validator->timestamp($team['lastUpdated'] ?? null),
            ];
            foreach ($team['squad'] ?? [] as $player) {
                $playerRows[] = [
                    'external_id' => $player['id'],
                    'name' => $player['name'],
                    'first_name' => $player['firstName'] ?? null,
                    'last_name' => $player['lastName'] ?? null,
                    'date_of_birth' => $player['dateOfBirth'] ?? null,
                    'nationality' => $player['nationality'] ?? null,
                    'source_updated_at' => $this->validator->timestamp($player['lastUpdated'] ?? null),
                ];
            }
        }

        Team::upsert($teamRows, [
            'external_id',
        ], [
            'area_id',
            'name',
            'short_name',
            'tla',
            'crest_url',
            'source_updated_at',
        ]);
        foreach (array_chunk($playerRows, 500) as $chunk) {
            Player::upsert($chunk, [
                'external_id',
            ], [
                'name',
                'first_name',
                'last_name',
                'date_of_birth',
                'nationality',
                'source_updated_at',
            ]);
        }

        $teamIds = Team::whereIn('external_id', array_column($teamRows, 'external_id'))->pluck('id', 'external_id');
        $playerIds = Player::whereIn('external_id', array_column($playerRows, 'external_id'))->pluck('id', 'external_id');
        $snapshotTeams = [];
        foreach ($teams as $team) {
            $snapshotTeams[] = [
                'snapshot_id' => $snapshot->id,
                'team_id' => $teamIds[$team['id']],
                'name' => $team['name'],
                'short_name' => $team['shortName'] ?? null,
                'crest_url' => $team['crest'] ?? null,
                'metadata' => json_encode($this->profiles->map($team), JSON_THROW_ON_ERROR),
            ];
        }
        SnapshotTeam::upsert($snapshotTeams, [
            'snapshot_id',
            'team_id',
        ], [
            'name',
            'short_name',
            'crest_url',
            'metadata',
        ]);
        $snapshotTeamIds = SnapshotTeam::where('snapshot_id', $snapshot->id)->pluck('id', 'team_id');
        $snapshotPlayers = [];
        foreach ($teams as $team) {
            foreach ($team['squad'] ?? [] as $player) {
                $snapshotPlayers[] = [
                    'snapshot_team_id' => $snapshotTeamIds[$teamIds[$team['id']]],
                    'player_id' => $playerIds[$player['id']],
                    'name' => $player['name'],
                    'position' => $player['position'] ?? null,
                    'shirt_number' => $player['shirtNumber'] ?? null,
                ];
            }
        }

        foreach (array_chunk($snapshotPlayers, 500) as $chunk) {
            SnapshotPlayer::upsert($chunk, [
                'snapshot_team_id',
                'player_id',
            ], [
                'name',
                'position',
                'shirt_number',
            ]);
        }

        Team::whereIn('id', $teamIds->values())->whereNull('first_published_at')->update([
            'first_published_at' => $publishedAt,
        ]);
        Player::whereIn('id', $playerIds->values())->whereNull('first_published_at')->update([
            'first_published_at' => $publishedAt,
        ]);
    }
}
