<?php

namespace App\Services\Football;

use App\Http\Resources\ImportResource;
use App\Models\Import;
use App\Models\Team;
use App\Models\User;
use App\Services\ImportCoordinator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TeamDetails
{
    public function __construct(private FootballReadService $football, private ImportCoordinator $imports) {}

    public function get(int $externalId, User $user): array
    {
        $team = $this->resolve($externalId, $user);
        $data = $this->football->team($externalId, $team);
        $data['has_data'] = $team->first_published_at !== null;
        if (! $data['has_data'] && ! $data['import']) {
            $data['import'] = (new ImportResource($this->imports->startEntity($team, $user->id)))->resolve();
        }
        $pending = in_array($data['import']['status'] ?? null, ['pending', 'processing', 'waiting_worker', 'waiting_rate_limit'], true);

        return ['status' => ! $data['has_data'] && $pending ? 202 : 200, 'data' => $data];
    }

    public function refresh(int $externalId, User $user): Import
    {
        return $this->imports->startEntity($this->resolve($externalId, $user), $user->id);
    }

    private function resolve(int $externalId, User $user): Team
    {
        $team = Team::with('area')->where('external_id', $externalId)->first();
        if ((! $team || ! $team->first_published_at) && ! $user->hasPermission('football.manage')) {
            throw new NotFoundHttpException('No hay información publicada de este equipo. Un usuario con permiso de importación puede solicitarla al proveedor.');
        }

        return $team ?? Team::firstOrCreate(['external_id' => $externalId], ['name' => 'Equipo #'.$externalId]);
    }
}
