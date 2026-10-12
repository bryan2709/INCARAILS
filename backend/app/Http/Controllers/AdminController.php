<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignPermissionsRequest;
use App\Http\Requests\AssignRolesRequest;
use App\Http\Requests\PermissionRequest;
use App\Http\Requests\RoleRequest;
use App\Http\Requests\UserRequest;
use App\Http\Requests\UserStatusRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\AdminMutationService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;

class AdminController
{
    public function __construct(private AdminMutationService $administration) {}

    public function users(): JsonResponse
    {
        return Api::ok(User::with('roles')->orderBy('id')->get());
    }

    public function user(int $id): JsonResponse
    {
        return Api::ok(User::with('roles')->findOrFail($id));
    }

    public function createUser(UserRequest $request): JsonResponse
    {
        return Api::ok(User::create($request->validated()), 'Usuario creado.', [], 201);
    }

    public function updateUser(UserRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update($request->validated());

        return Api::ok($user->fresh(), 'Usuario actualizado.');
    }

    public function statusUser(UserStatusRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($user->id === $request->user('api')->id && ! $request->boolean('is_active')) {
            return Api::fail('SELF_DISABLE', 'No puede desactivar su propia cuenta.', 422);
        }

        return Api::ok($this->administration->status($user, $request->validated()));
    }

    public function userRoles(AssignRolesRequest $request, int $id): JsonResponse
    {
        $user = $this->administration->assignRoles(User::findOrFail($id), $request->user('api'), $request->validated('role_ids'));

        return Api::ok($user, 'Roles asignados.');
    }

    public function roles(): JsonResponse
    {
        return Api::ok(Role::with('permissions')->orderBy('id')->get());
    }

    public function role(int $id): JsonResponse
    {
        return Api::ok(Role::with('permissions')->findOrFail($id));
    }

    public function createRole(RoleRequest $request): JsonResponse
    {
        return Api::ok(Role::create($request->validated()), 'Rol creado.', [], 201);
    }

    public function updateRole(RoleRequest $request, int $id): JsonResponse
    {
        return Api::ok($this->administration->updateRole(Role::findOrFail($id), $request->user('api'), $request->validated()));
    }

    public function rolePermissions(AssignPermissionsRequest $request, int $id): JsonResponse
    {
        return Api::ok($this->administration->assignPermissions(Role::findOrFail($id), $request->user('api'), $request->validated('permission_ids')));
    }

    public function permissions(): JsonResponse
    {
        return Api::ok(Permission::orderBy('id')->get());
    }

    public function permission(int $id): JsonResponse
    {
        return Api::ok(Permission::findOrFail($id));
    }

    public function createPermission(PermissionRequest $request): JsonResponse
    {
        return Api::ok(Permission::create($request->validated() + [
            'is_system' => false,
            'is_active' => true,
        ]), 'Permiso creado.', [], 201);
    }

    public function updatePermission(PermissionRequest $request, int $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);
        $attributes = $request->validated();
        $renamed = isset($attributes['code']) && $attributes['code'] !== $permission->code;
        $disabled = array_key_exists('is_active', $attributes) && ! $request->boolean('is_active');
        if ($permission->is_system && ($renamed || $disabled)) {
            return Api::fail('SYSTEM_PERMISSION', 'Los permisos del sistema no pueden renombrarse ni desactivarse.', 422);
        }

        return Api::ok($this->administration->updatePermission($permission, $request->user('api'), $attributes));
    }
}
