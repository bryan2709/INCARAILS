<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\CompetitionSnapshot;
use App\Models\ExternalTask;
use App\Services\Imports\SourcePayloadValidator;
use App\Services\Imports\TeamProfileMapper;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTeamProfiles extends Command
{
    protected $signature = 'football:backfill-team-profiles {--competition= : ID externo de competición}';

    protected $description = 'Recuperar perfiles de equipos desde respuestas guardadas de snapshots activos';

    public function handle(TeamProfileMapper $profiles, SourcePayloadValidator $validator): int
    {
        $externalId = $this->option('competition');
        if ($externalId !== null && (! ctype_digit((string) $externalId) || (int) $externalId < 1)) {
            $this->error('El ID de competición debe ser un entero positivo.');

            return self::FAILURE;
        }

        $updated = 0;
        $skipped = 0;
        CompetitionSnapshot::with(['competition', 'season', 'teams.team'])
            ->where('build_status', 'published')
            ->whereIn('id', Competition::select('active_snapshot_id')->whereNotNull('active_snapshot_id'))
            ->when($externalId, fn ($query) => $query->whereHas('competition', fn ($competition) => $competition->where('external_id', $externalId)))
            ->chunkById(25, function ($snapshots) use ($profiles, $validator, &$updated, &$skipped): void {
                foreach ($snapshots as $snapshot) {
                    $task = ExternalTask::where('status', 'completed')->whereNotNull('response_json')
                        ->whereHas('step', fn ($query) => $query->where('import_id', $snapshot->import_id)->where('step_key', 'teams'))
                        ->first();
                    if (! $task) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $validator->teams($snapshot->competition, $snapshot->season, $task->response_json);
                    } catch (DomainException $exception) {
                        $this->warn('Snapshot #'.$snapshot->id.': '.$exception->getMessage());
                        $skipped++;

                        continue;
                    }

                    $source = collect($task->response_json['teams'])->keyBy('id');
                    if ($source->count() !== $snapshot->teams->count() || $snapshot->teams->contains(fn ($team) => ($source->get($team->team->external_id)['name'] ?? null) !== $team->name)) {
                        $this->warn('Snapshot #'.$snapshot->id.': los equipos guardados no coinciden con la publicación.');
                        $skipped++;

                        continue;
                    }

                    $updated += DB::transaction(function () use ($snapshot, $source, $profiles): int {
                        $competition = Competition::whereKey($snapshot->competition_id)->lockForUpdate()->firstOrFail();
                        if ($competition->active_snapshot_id !== $snapshot->id) {
                            return 0;
                        }

                        $changes = 0;
                        foreach ($snapshot->teams as $team) {
                            $metadata = array_merge($team->metadata ?? [], $profiles->map($source->get($team->team->external_id)));
                            if ($metadata != $team->metadata) {
                                $team->update(['metadata' => $metadata]);
                                $changes++;
                            }
                        }

                        return $changes;
                    }, 3);
                }
            });
        $this->info("Perfiles actualizados: $updated. Snapshots omitidos: $skipped.");

        return self::SUCCESS;
    }
}
