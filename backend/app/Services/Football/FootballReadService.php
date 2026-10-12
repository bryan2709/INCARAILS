<?php

namespace App\Services\Football;

use App\Http\Resources\ImportResource;
use App\Models\Competition;
use App\Models\CompetitionSeason;
use App\Models\CompetitionSnapshot;
use App\Models\Import;
use App\Models\Player;
use App\Models\SnapshotPlayer;
use App\Models\SnapshotTeam;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;

class FootballReadService
{
    public function competitions(array $filters): Builder
    {
        return $this->search(Competition::with([
            'currentSeason',
            'activeSnapshot.season',
        ])->where(fn (Builder $query) => $query->whereNotNull('catalog_fetched_at')->orWhereNotNull('active_snapshot_id')), $filters)
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->orderBy('name')->orderBy('id');
    }

    public function teams(array $filters): Builder
    {
        return $this->search(Team::whereNotNull('first_published_at'), $filters)->orderBy('name')->orderBy('id');
    }

    public function players(array $filters): Builder
    {
        return $this->search(Player::whereNotNull('first_published_at'), $filters)->orderBy('name')->orderBy('id');
    }

    public function team(int $externalId, ?Team $team = null): array
    {
        $team ??= Team::with('area')->where('external_id', $externalId)->whereNotNull('first_published_at')->firstOrFail();
        $participations = SnapshotTeam::with([
            'players.player',
            'snapshot.competition',
            'snapshot.season',
        ])
            ->where('team_id', $team->id)
            ->whereIn('snapshot_id', $this->activeSnapshots())
            ->orderByDesc('snapshot_id')->orderBy('id')
            ->get()->map(fn (SnapshotTeam $participation) => [
                'snapshot' => $this->snapshotIdentity($participation->snapshot),
                'competition' => $this->competitionIdentity($participation->snapshot->competition),
                'season' => $this->seasonIdentity($participation->snapshot->season),
                'name' => $participation->name,
                'crest' => $participation->crest_url,
                'short_name' => $participation->short_name,
                'profile' => $participation->metadata,
                'last_updated_at' => $participation->snapshot->published_at?->toIso8601String(),
                'players' => $participation->players->map(fn (SnapshotPlayer $player) => [
                    'id' => $player->player->external_id,
                    'name' => $player->name,
                    'date_of_birth' => $player->player->date_of_birth,
                    'nationality' => $player->player->nationality,
                    'position' => $player->position,
                    'shirt_number' => $player->shirt_number,
                ]),
            ]);

        return [
            'team' => $team,
            'participations' => $participations,
            'last_updated_at' => $team->source_fetched_at?->toIso8601String() ?? $participations->max('last_updated_at'),
            'import' => $this->entityImport('team_id', $team->id),
        ];
    }

    public function player(int $externalId, ?Player $player = null): array
    {
        $player ??= Player::where('external_id', $externalId)->whereNotNull('first_published_at')->firstOrFail();
        $participations = SnapshotPlayer::with([
            'snapshotTeam.team',
            'snapshotTeam.snapshot.competition',
            'snapshotTeam.snapshot.season',
        ])
            ->where('player_id', $player->id)
            ->whereIn('snapshot_team_id', SnapshotTeam::select('id')->whereIn('snapshot_id', $this->activeSnapshots()))
            ->get()->map(function (SnapshotPlayer $participation): array {
                $team = $participation->snapshotTeam;

                return [
                    'snapshot' => $this->snapshotIdentity($team->snapshot),
                    'team' => [
                        'id' => $team->team->external_id,
                        'name' => $team->name,
                        'short_name' => $team->short_name,
                        'tla' => $team->metadata['tla'] ?? null,
                        'crest' => $team->crest_url,
                    ],
                    'competition' => $this->competitionIdentity($team->snapshot->competition),
                    'season' => $this->seasonIdentity($team->snapshot->season),
                    'position' => $participation->position,
                    'shirt_number' => $participation->shirt_number,
                    'last_updated_at' => $team->snapshot->published_at?->toIso8601String(),
                ];
            });

        return [
            'player' => $player,
            'participations' => $participations,
            'last_updated_at' => $player->source_fetched_at?->toIso8601String() ?? $participations->max('last_updated_at'),
            'import' => $this->entityImport('player_id', $player->id),
        ];
    }

    private function activeSnapshots(): Builder
    {
        return Competition::select('active_snapshot_id')->whereNotNull('active_snapshot_id');
    }

    private function entityImport(string $column, int $id): ?array
    {
        $import = Import::where($column, $id)->latest('id')->first();

        return $import ? (new ImportResource($import))->resolve() : null;
    }

    private function search(Builder $query, array $filters): Builder
    {
        $search = $filters['search'] ?? null;

        return $query->when(filled($search), fn (Builder $query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'));
    }

    private function snapshotIdentity(CompetitionSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'published_at' => $snapshot->published_at?->toIso8601String(),
        ];
    }

    private function competitionIdentity(Competition $competition): array
    {
        return [
            'id' => $competition->external_id,
            'name' => $competition->name,
            'code' => $competition->code,
            'type' => $competition->type,
            'emblem' => $competition->emblem_url,
        ];
    }

    private function seasonIdentity(CompetitionSeason $season): array
    {
        return [
            'id' => $season->external_id,
            'start_date' => $season->start_date,
            'end_date' => $season->end_date,
        ];
    }
}
