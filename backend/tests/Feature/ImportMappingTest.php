<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\ExternalTask;
use App\Models\Import;
use App\Models\ImportStep;
use App\Models\Team;
use App\Services\ImportPersistence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function task(Import $import, string $step): ExternalTask
    {
        $row = ImportStep::updateOrCreate(['import_id' => $import->id, 'step_key' => $step], ['status' => 'completed']);

        return ExternalTask::updateOrCreate(['import_step_id' => $row->id, 'request_key' => $step], ['resource' => $step, 'status' => 'completed']);
    }

    public function test_publicacion_con_camisetas_nulas_y_reimportacion_sin_duplicados(): void
    {
        $c = Competition::create(['external_id' => 2016, 'name' => 'Championship']);
        $detail = ['id' => 2016, 'name' => 'Championship', 'currentSeason' => ['id' => 2509, 'startDate' => '2026-08-14', 'endDate' => '2027-05-01']];
        $teams = ['count' => 1, 'competition' => ['id' => 2016], 'season' => ['id' => 2509], 'teams' => [['id' => 59, 'name' => 'Blackburn Rovers FC', 'lastUpdated' => '2022-02-19T08:50:27Z', 'squad' => [['id' => 4204, 'name' => 'Aynsley Pears', 'position' => 'Goalkeeper']]]]];
        $svc = app(ImportPersistence::class);
        foreach ([1, 2] as $n) {
            $import = Import::create(['competition_id' => $c->id, 'type' => 'refresh', 'status' => 'pending', 'active_scope_key' => 'competition:'.$c->id]);
            $svc->completed($this->task($import, 'detail'), $detail);
            $svc->completed($this->task($import, 'teams'), $teams);
            $this->assertSame('completed', $import->fresh()->status);
        }
        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('players', 1);
        $this->assertDatabaseCount('competition_snapshots', 2);
        $this->assertDatabaseHas('snapshot_players', ['shirt_number' => null, 'position' => 'Goalkeeper']);
        $this->assertDatabaseHas('teams', ['external_id' => 59, 'source_updated_at' => '2022-02-19 08:50:27']);
        $this->assertNotNull(Team::first()->first_published_at);
    }

    public function test_squad_ausente_publica_equipo_sin_jugadores(): void
    {
        $c = Competition::create(['external_id' => 2016, 'name' => 'Championship']);
        $import = Import::create(['competition_id' => $c->id, 'type' => 'initial', 'status' => 'pending', 'active_scope_key' => 'competition:'.$c->id]);
        $svc = app(ImportPersistence::class);
        $svc->completed($this->task($import, 'detail'), ['id' => 2016, 'currentSeason' => ['id' => 2509, 'startDate' => '2026-08-14']]);
        $svc->completed($this->task($import, 'teams'), ['count' => 1, 'competition' => ['id' => 2016], 'season' => ['id' => 2509], 'teams' => [['id' => 59, 'name' => 'Sin plantilla']]]);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(1, $import->fresh()->quality_summary['missing_squads_count']);
        $this->assertDatabaseHas('teams', ['external_id' => 59, 'name' => 'Sin plantilla']);
        $this->assertDatabaseCount('competition_snapshots', 1);
        $this->assertDatabaseCount('snapshot_players', 0);
    }
}
