<?php

namespace App\Services\Administration;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSafety;

class AdminMutationService
{
    public function __construct(private AdminSafety $safety) {}

    public function status(User $user, array $attributes): User
    {
        $this->safety->mutate(fn () => $user->update($attributes));

        return $user->fresh();
    }

    public function assignRoles(User $user, User $actor, array $roleIds): User
    {
        $this->safety->mutate(function () use ($user, $actor, $roleIds): void {
            $this->safety->canAssignRoles($actor, $roleIds);
            $user->roles()->sync($roleIds);
        });

        return $user->load('roles');
    }

    public function updateRole(Role $role, User $actor, array $attributes): Role
    {
        $this->safety->mutate(function () use ($role, $actor, $attributes): void {
            if (! $role->is_active && ($attributes['is_active'] ?? false)) {
                $this->safety->canGrant($actor, $role->permissions()->where('is_active', true)->pluck('permissions.id')->all());
            }

            $role->update($attributes);
        });

        return $role->fresh();
    }

    public function assignPermissions(Role $role, User $actor, array $permissionIds): Role
    {
        $this->safety->mutate(function () use ($role, $actor, $permissionIds): void {
            $this->safety->canGrant($actor, $permissionIds);
            $role->permissions()->sync($permissionIds);
        });

        return $role->load('permissions');
    }

    public function updatePermission(Permission $permission, User $actor, array $attributes): Permission
    {
        $this->safety->mutate(function () use ($permission, $actor, $attributes): void {
            if (! $permission->is_active && ($attributes['is_active'] ?? false)) {
                $this->safety->canGrant($actor, [
                    $permission->id,
                ]);
            }

            $permission->update($attributes);
        });

        return $permission->fresh();
    }
}
