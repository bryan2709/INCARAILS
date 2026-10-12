<?php

namespace App\Http\Controllers;

use App\Http\Requests\FootballListRequest;
use App\Http\Resources\CompetitionResource;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\TeamResource;
use App\Services\Football\CompetitionDetails;
use App\Services\Football\FootballReadService;
use App\Services\Football\PlayerDetails;
use App\Services\Football\TeamDetails;
use App\Services\ImportCoordinator;
use App\Support\Api;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FootballController
{
    public function __construct(private FootballReadService $football) {}

    public function competitions(FootballListRequest $request, ImportCoordinator $imports): JsonResponse
    {
        if ($request->user('api')->hasPermission('football.manage')) {
            $imports->ensureCatalog();
        }

        return $this->listing($request, $this->football->competitions($request->validated()), CompetitionResource::class, 'Competiciones');
    }

    public function competition(Request $request, int $competition_id, CompetitionDetails $details): JsonResponse
    {
        $result = $details->get($competition_id, $request->user('api'));

        return Api::ok($result['data'], $result['status'] === 202 ? 'Importación en curso.' : 'OK', [], $result['status']);
    }

    public function teams(FootballListRequest $request): JsonResponse
    {
        return $this->listing($request, $this->football->teams($request->validated()), TeamResource::class);
    }

    public function players(FootballListRequest $request): JsonResponse
    {
        return $this->listing($request, $this->football->players($request->validated()), PlayerResource::class);
    }

    public function team(Request $request, int $team_id, TeamDetails $details): JsonResponse
    {
        $result = $details->get($team_id, $request->user('api'));
        $result['data']['team'] = (new TeamResource($result['data']['team']))->resolve();

        return Api::ok($result['data'], $result['status'] === 202 ? 'Importación en curso.' : 'OK', [], $result['status']);
    }

    public function player(Request $request, int $player_id, PlayerDetails $details): JsonResponse
    {
        $result = $details->get($player_id, $request->user('api'));
        $result['data']['player'] = (new PlayerResource($result['data']['player']))->resolve();

        return Api::ok($result['data'], $result['status'] === 202 ? 'Importación en curso.' : 'OK', [], $result['status']);
    }

    private function listing(FootballListRequest $request, Builder $query, string $resource, string $message = 'Resultados'): JsonResponse
    {
        $page = $query->paginate($request->perPage());

        return Api::ok($resource::collection($page->items())->resolve(), $message, [
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }
}
