<?php

namespace App\Console\Commands;

use App\Services\ExternalTasks\TaskMaintenance;
use Illuminate\Console\Command;

class WakeExternalTasks extends Command
{
    protected $signature = 'football:wake';

    protected $description = 'Recupera tareas pendientes y limpia snapshots fuera de retención';

    public function handle(TaskMaintenance $maintenance): int
    {
        $maintenance->run();

        return self::SUCCESS;
    }
}
