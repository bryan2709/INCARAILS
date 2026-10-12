<?php

namespace Tests\Feature;

use App\Models\CompetitionSnapshot;
use App\Services\ImportCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsCompetitionImports;
use Tests\TestCase;

class SnapshotRetentionTest extends TestCase
{
    use BuildsCompetitionImports, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Queue::fake();
    }

    public function test_retired_snapshot_cleanup_preserves_active_snapshot_and_import_history(): void
    {
        $first = $this->prepareImport();
        $this->completeTask($first, 'teams', $this->sourceTeams());
        $oldId = $first->competition->fresh()->active_snapshot_id;
        $refresh = $this->prepareImport($first->competition->fresh());
        $this->completeTask($refresh, 'teams', $this->sourceTeams());
        $activeId = $refresh->competition->fresh()->active_snapshot_id;
        $this->travel(25)->hours();
        // Even an incorrect retirement timestamp must never delete the active pointer.
        CompetitionSnapshot::whereKey($activeId)->update(['retired_at' => now()->subHours(25)]);
        $this->artisan('football:wake')->assertSuccessful();

        $this->assertDatabaseMissing('competition_snapshots', ['id' => $oldId]);
        $this->assertDatabaseHas('competition_snapshots', ['id' => $activeId]);
        $this->assertDatabaseCount('imports', 2);
        $this->assertDatabaseCount('import_steps', 4);
        $this->assertDatabaseCount('external_tasks', 4);
        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseCount('players', 1);
    }

    public function test_failed_staging_retention_starts_at_failure(): void
    {
        $import = $this->prepareImport();
        $snapshot = CompetitionSnapshot::create([
            'competition_id' => $import->competition_id,
            'season_id' => $import->season_id,
            'import_id' => $import->id,
            'build_status' => 'staging',
            'created_at' => now()->subDays(2),
        ]);
        app(ImportCoordinator::class)->fail($import, 'SOURCE_VALIDATION_ERROR', 'Incomplete');
        $this->artisan('football:wake')->assertSuccessful();
        $this->assertDatabaseHas('competition_snapshots', ['id' => $snapshot->id]);
        $this->travel(25)->hours();
        $this->artisan('football:wake')->assertSuccessful();

        $this->assertDatabaseMissing('competition_snapshots', ['id' => $snapshot->id]);
        $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'failed']);
        $this->assertDatabaseCount('external_tasks', 2);
    }
}
