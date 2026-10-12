<?php

namespace App\Services\Football;

use App\Http\Resources\CompetitionResource;
use App\Http\Resources\CompetitionSnapshotResource;
use App\Models\Competition;
use App\Models\Import;
use App\Models\User;
use App\Services\ImportCoordinator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class CompetitionDetails
{
    public function __construct(private ImportCoordinator $imports) {}

    public function get(int $externalId, User $user): array
    {
        $canManage = $user->hasPermission('football.manage');
        $competition = Competition::with([
            'currentSeason',
            'activeSnapshot.season',
            'activeSnapshot.teams' => fn ($query) => $query->orderBy('name')->orderBy('id'),
            'activeSnapshot.teams.team:id,external_id',
            'activeSnapshot.teams.players' => fn ($query) => $query->orderBy('name')->orderBy('id'),
            'activeSnapshot.teams.players.player:id,external_id',
        ])->where('external_id', $externalId)->first();
        if (! $competition) {
            if (! $canManage) {
                throw new NotFoundHttpException('No hay información local de esta competición. Un usuario con permiso de importación puede solicitarla al proveedor.');
            }
            $competition = Competition::firstOrCreate([
                'external_id' => $externalId,
            ], [
                'name' => 'Competición #'.$externalId,
            ]);
        }
        $latest = Import::where('competition_id', $competition->id)->latest('id')->first();
        $active = $latest?->active_scope_key !== null ? $latest : null;

        if (! $competition->active_snapshot_id && ! $latest && $canManage) {
            $active = $this->imports->start($competition, $user->id);
        }

        if (! $competition->active_snapshot_id && ! $active && $canManage && $latest?->status === 'failed' && $latest->error_code === 'SOURCE_UNAVAILABLE') {
            throw new ServiceUnavailableHttpException(null, $latest->error_message ?: 'La última importación falló por indisponibilidad del proveedor.');
        }

        $competition->import_status = $active ? 'processing' : ($latest?->status === 'failed' ? 'failed' : ($competition->active_snapshot_id ? 'published' : 'not_imported'));
        $import = $active ?? $latest;

        return [
            'status' => ! $competition->active_snapshot_id && $active && $canManage ? 202 : 200,
            'data' => [
                'competition' => (new CompetitionResource($competition))->resolve(),
                'snapshot' => $competition->activeSnapshot
                    ? (new CompetitionSnapshotResource($competition->activeSnapshot))->resolve()
                    : null,
                'import' => $import ? [
                    'id' => $import->id,
                    'status' => $import->status,
                    'error_code' => $import->error_code,
                    'error_message' => $import->error_message,
                    'quality_summary' => $import->quality_summary,
                    'url' => '/api/imports/'.$import->id,
                ] : null,
            ],
        ];
    }
}
