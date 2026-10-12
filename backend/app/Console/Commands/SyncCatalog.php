<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Services\ImportCoordinator;
use Illuminate\Console\Command;

class SyncCatalog extends Command
{
    protected $signature = 'football:catalog {--force : Permitir intento nuevo si la sincronización anterior terminó}';

    protected $description = 'Inicia sincronización de catálogo mediante los workers y el limitador compartido';

    public function handle(ImportCoordinator $coordinator): int
    {
        if (! $this->option('force')) {
            $existing = $coordinator->ensureCatalog();
            $this->info('Catálogo: '.($existing?->id ?? 'ya inicializado'));

            return self::SUCCESS;
        }
        if (Import::where('active_scope_key', 'catalog')->exists()) {
            $this->warn('Sincronización en curso');

            return self::SUCCESS;
        }
        $existing = Import::where('type', 'catalog')->latest()->first();
        if ($existing) {
            $old = Import::find($existing->id);
            $old->update([
                'active_scope_key' => null,
            ]);
        } // force creates a fresh catalog import

        $import = Import::create([
            'type' => 'catalog',
            'status' => 'pending',
            'active_scope_key' => 'catalog',
        ]);
        $coordinator->task($import, 'catalog', 'catalog');
        $this->info('Importación catálogo #'.$import->id);

        return self::SUCCESS;
    }
}
