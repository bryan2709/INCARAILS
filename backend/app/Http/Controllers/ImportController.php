<?php

namespace App\Http\Controllers;

use App\Http\Resources\ImportResource;
use App\Models\Competition;
use App\Models\Import;
use App\Models\Player;
use App\Models\Team;
use App\Services\Football\PlayerDetails;
use App\Services\Football\TeamDetails;
use App\Services\ImportCoordinator;
use App\Support\Api;
use Illuminate\Http\Request;

class ImportController
{
    public function show(int $import_id)
    {
        $import = Import::with('steps')->findOrFail($import_id);

        return Api::ok((new ImportResource($import))->resolve());
    }

    public function refresh(Request $request, int $competition_id, ImportCoordinator $coordinator)
    {
        $c = Competition::where('external_id', $competition_id)->firstOrFail();
        $active = Import::where('active_scope_key', 'competition:'.$c->id)->first();
        $job = $active ?? $coordinator->start($c, $request->user('api')->id, 'refresh');

        return Api::ok([
            'id' => $job->id,
            'status' => $job->status,
        ], 'Importación en curso.', [], 202);
    }

    public function retry(Request $request, int $import_id, ImportCoordinator $coordinator)
    {
        $old = Import::findOrFail($import_id);
        if ($old->status !== 'failed' || (! $old->competition_id && ! $old->team_id && ! $old->player_id)) {
            return Api::fail('RETRY_NOT_ALLOWED', 'Solo se reintentan actualizaciones fallidas.', 422);
        }
        $job = $old->competition_id
            ? $coordinator->start($old->competition, $request->user('api')->id, 'retry', $old)
            : $coordinator->startEntity($old->team_id ? Team::findOrFail($old->team_id) : Player::findOrFail($old->player_id), $request->user('api')->id, $old);

        return Api::ok([
            'id' => $job->id,
            'status' => $job->status,
            'retry_of_import_id' => $old->id,
        ], 'Reintento solicitado.', [], 202);
    }

    public function refreshTeam(Request $request, int $team_id, TeamDetails $details)
    {
        return Api::ok((new ImportResource($details->refresh($team_id, $request->user('api'))))->resolve(), 'Actualización en curso.', [], 202);
    }

    public function refreshPlayer(Request $request, int $player_id, PlayerDetails $details)
    {
        return Api::ok((new ImportResource($details->refresh($player_id, $request->user('api'))))->resolve(), 'Actualización en curso.', [], 202);
    }
}
