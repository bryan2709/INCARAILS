<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminSafety
{
    private const ADMIN_PERMISSIONS = [
        'football.view',
        'football.manage',
        'administration.manage',
    ];

    public function mutate(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            User::select('id')->orderBy('id')->lockForUpdate()->get();
            $result = $operation();
            $administrators = User::where('is_active', true);
            foreach (self::ADMIN_PERMISSIONS as $code) {
                $administrators->whereHas('roles', fn ($roles) => $roles->where('is_active', true)
                    ->whereHas('permissions', fn ($permissions) => $permissions->where('is_active', true)->where('code', $code)));
            }

            if (! $administrators->exists()) {
                throw ValidationException::withMessages([
                    'roles' => [
                        'Debe mantenerse un administrador activo con los tres permisos.',
                    ],
                ]);
            }

            return $result;
        }, 3);
    }

    public function canGrant(User $actor, array $permissionIds): void
    {
        $granted = Permission::whereIn('id', $permissionIds)->pluck('code')->all();
        if (array_diff($granted, $actor->permissionCodes())) {
            abort(403, 'No se permite conceder permisos que usted no posee.');
        }
    }

    public function canAssignRoles(User $actor, array $roleIds): void
    {
        $roles = Role::with('permissions')->whereIn('id', $roleIds)->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw ValidationException::withMessages([
                'roles' => [
                    'Rol inexistente.',
                ],
            ]);
        }
        // Check inactive roles too: assigning one must not stage future privilege escalation.

        $permissions = $roles->flatMap(fn (Role $role) => $role->permissions->where('is_active', true)->pluck('id'))->unique()->all();
        $this->canGrant($actor, $permissions);
    }
}
