<?php

namespace App\Services\Football;

use App\Http\Resources\ImportResource;
use App\Models\Import;
use App\Models\Player;
use App\Models\User;
use App\Services\ImportCoordinator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PlayerDetails
{
    public function __construct(private FootballReadService $football, private ImportCoordinator $imports) {}

    public function get(int $externalId, User $user): array
    {
        $player = $this->resolve($externalId, $user);
        $data = $this->football->player($externalId, $player);
        $data['has_data'] = $player->first_published_at !== null;
        if (! $data['has_data'] && ! $data['import']) {
            $data['import'] = (new ImportResource($this->imports->startEntity($player, $user->id)))->resolve();
        }
        $pending = in_array($data['import']['status'] ?? null, ['pending', 'processing', 'waiting_worker', 'waiting_rate_limit'], true);

        return ['status' => ! $data['has_data'] && $pending ? 202 : 200, 'data' => $data];
    }

    public function refresh(int $externalId, User $user): Import
    {
        return $this->imports->startEntity($this->resolve($externalId, $user), $user->id);
    }

    private function resolve(int $externalId, User $user): Player
    {
        $player = Player::where('external_id', $externalId)->first();
        if ((! $player || ! $player->first_published_at) && ! $user->hasPermission('football.manage')) {
            throw new NotFoundHttpException('No hay información publicada de este jugador. Un usuario con permiso de importación puede solicitarla al proveedor.');
        }

        return $player ?? Player::firstOrCreate(['external_id' => $externalId], ['name' => 'Jugador #'.$externalId]);
    }
}
