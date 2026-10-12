<?php

namespace Database\Seeders;

use App\Models\ExternalCredential;
use App\Models\ExternalQuotaPool;
use App\Models\ExternalWorkerConfig;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'football.view' => 'Consultar ligas equipos y jugadores',
            'football.manage' => 'Importar actualizar y reintentar',
            'administration.manage' => 'Administrar usuarios roles y permisos',
        ] as $code => $name) {
            Permission::updateOrCreate([
                'code' => $code,
            ], [
                'name' => $name,
                'is_system' => true,
                'is_active' => true,
            ]);
        }

        foreach ([
            'admin' => 'Administrador',
            'readonly' => 'Solo consulta',
        ] as $code => $name) {
            Role::updateOrCreate([
                'code' => $code,
            ], [
                'name' => $name,
                'is_active' => true,
            ]);
        }
        Role::where('code', 'admin')->firstOrFail()->permissions()->sync(Permission::whereIn('code', [
            'football.view',
            'football.manage',
            'administration.manage',
        ])->pluck('id')->all());
        Role::where('code', 'readonly')->firstOrFail()->permissions()->sync(Permission::where('code', 'football.view')->pluck('id')->all());
        foreach ([
            'ADMIN_SEED_EMAIL' => 'admin',
            'READONLY_SEED_EMAIL' => 'readonly',
        ] as $emailKey => $role) {
            $email = env($emailKey);
            $password = env($role === 'admin' ? 'ADMIN_SEED_PASSWORD' : 'READONLY_SEED_PASSWORD');
            if (! $email || ! $password) {
                $this->command->warn("Sin credenciales seed para $role. Defina $emailKey y su contraseña en .env.");

                continue;
            }

            $u = User::firstOrNew([
                'email' => $email,
            ]);
            if (! $u->exists) {
                $u->name = $role === 'admin' ? env('ADMIN_SEED_NAME', 'Administrador') : 'Lectura';
                $u->password = Hash::make($password);
                $u->is_active = true;
                $u->save();
            }
            $u->roles()->syncWithoutDetaching(Role::where('code', $role)->pluck('id'));
        }

        $pool = ExternalQuotaPool::updateOrCreate([
            'name' => 'football-data-global',
        ], [
            'provider' => 'football-data',
            'max_requests' => config('football.global_limit'),
            'window_seconds' => config('football.window'),
            'is_enabled' => true,
        ]);
        foreach ([
            'A',
            'B',
            'C',
        ] as $letter) {
            $secret = 'FOOTBALL_TOKEN_'.$letter;
            $cre = ExternalCredential::updateOrCreate([
                'alias' => 'credential-'.strtolower($letter),
            ], [
                'secret_reference' => $secret,
                'quota_pool_id' => $pool->id,
            ]);
            ExternalWorkerConfig::updateOrCreate([
                'worker_key' => 'worker-'.strtolower($letter),
            ], [
                'credential_id' => $cre->id,
                'max_requests' => 10,
                'window_seconds' => 60,
                'cooldown_seconds' => 60,
            ]);
        }
    }
}
