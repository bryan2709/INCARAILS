<?php

namespace App\Services\Imports;

use App\Models\Import;
use App\Models\Player;
use App\Models\Team;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;

class EntityDetailPublisher
{
    public function __construct(private SourcePayloadValidator $validator, private SourceCatalogMapper $mapper, private TeamProfileMapper $profiles) {}

    public function team(Import $import, array $data): void
    {
        $team = Team::findOrFail($import->team_id);
        $quality = $this->validator->teamDetail($team->external_id, $data);
        $this->publish($import, $team, $quality, function (Team $team) use ($data): void {
            $players = $data['squad'] ?? [];
            $rows = array_map(fn (array $player) => $this->playerRow($player), $players);
            foreach (array_chunk($rows, 500) as $chunk) {
                Player::upsert($chunk, ['external_id'], ['name', 'first_name', 'last_name', 'date_of_birth', 'nationality', 'source_updated_at']);
            }
            Player::whereIn('external_id', array_column($rows, 'external_id'))->whereNull('first_published_at')->update(['first_published_at' => now()]);
            $profile = $this->teamProfile($data);
            $profile['squad'] = array_map(fn (array $player) => $this->playerProfile($player), $players);
            $team->update([
                'name' => $data['name'],
                'short_name' => $data['shortName'] ?? null,
                'tla' => $data['tla'] ?? null,
                'crest_url' => $data['crest'] ?? null,
                'area_id' => $this->mapper->area($data['area'] ?? null),
                'source_updated_at' => $this->validator->timestamp($data['lastUpdated'] ?? null),
                'source_profile' => $profile,
                'source_fetched_at' => now(),
                'first_published_at' => $team->first_published_at ?? now(),
            ]);
        });
    }

    public function player(Import $import, array $data): void
    {
        $player = Player::findOrFail($import->player_id);
        if ((int) ($data['id'] ?? -1) !== $player->external_id) {
            throw new DomainException('SOURCE_PLAYER_MISMATCH');
        }
        $this->validator->player($data);
        $quality = ['source_fetched_at' => now()->toIso8601String()];
        $this->publish($import, $player, $quality, function (Player $player) use ($data): void {
            $profile = $this->playerProfile($data);
            $profile['current_team'] = isset($data['currentTeam']) ? $this->teamProfile($data['currentTeam']) : null;
            if ($profile['current_team'] !== null) {
                $contract = $data['currentTeam']['contract'] ?? [];
                $profile['current_team']['contract'] = [
                    'start' => is_string($contract['start'] ?? null) ? $contract['start'] : null,
                    'until' => is_string($contract['until'] ?? null) ? $contract['until'] : null,
                ];
            }
            $player->update([
                ...$this->playerRow($data),
                'source_profile' => $profile,
                'source_fetched_at' => now(),
                'first_published_at' => $player->first_published_at ?? now(),
            ]);
        });
    }

    private function publish(Import $import, Team|Player $entity, array $quality, Closure $write): void
    {
        DB::transaction(function () use ($import, $entity, $quality, $write): void {
            $entity = $entity->newQuery()->whereKey($entity->id)->lockForUpdate()->firstOrFail();
            $import = Import::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if (in_array($import->status, ['completed', 'failed'], true)) {
                return;
            }
            $kind = $entity instanceof Team ? 'team' : 'player';
            if ($import->active_scope_key !== $kind.':'.$entity->id) {
                throw new DomainException('SOURCE_ENTITY_MISMATCH');
            }
            $write($entity);
            $import->update([
                'status' => 'completed',
                'active_scope_key' => null,
                'started_at' => $import->started_at ?? now(),
                'completed_at' => now(),
                'quality_summary' => $quality,
            ]);
        }, 3);
    }

    private function playerRow(array $data): array
    {
        return [
            'external_id' => (int) $data['id'],
            'name' => $data['name'],
            'first_name' => $data['firstName'] ?? null,
            'last_name' => $data['lastName'] ?? null,
            'date_of_birth' => $data['dateOfBirth'] ?? null,
            'nationality' => $data['nationality'] ?? null,
            'source_updated_at' => $this->validator->timestamp($data['lastUpdated'] ?? null),
        ];
    }

    private function playerProfile(array $data): array
    {
        $row = $this->playerRow($data);
        unset($row['external_id']);

        return [
            ...$row,
            'id' => (int) $data['id'],
            'source_updated_at' => $data['lastUpdated'] ?? null,
            'position' => $data['position'] ?? $data['section'] ?? null,
            'shirt_number' => isset($data['shirtNumber']) ? (int) $data['shirtNumber'] : null,
        ];
    }

    private function teamProfile(array $data): array
    {
        return [
            ...$this->profiles->map($data),
            'id' => (int) $data['id'],
            'name' => $data['name'],
            'short_name' => $data['shortName'] ?? null,
            'crest' => $data['crest'] ?? null,
        ];
    }
}
