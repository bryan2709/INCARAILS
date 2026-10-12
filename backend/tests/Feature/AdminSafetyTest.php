<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AdminSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        User::query()->delete();
        Passport::actingAs($this->user('admin', 'admin'));
    }

    private function user(string $name, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $name.'@test.local', 'password' => 'password-demo-123', 'is_active' => true]);
        $user->roles()->attach(Role::where('code', $role)->firstOrFail());

        return $user;
    }

    private function limitedAdministrator(): User
    {
        $role = Role::create(['code' => 'acl-only', 'name' => 'ACL only', 'is_active' => true]);
        $role->permissions()->attach(Permission::where('code', 'administration.manage')->firstOrFail());

        return $this->user('limited', 'acl-only');
    }

    public function test_last_administrator_cannot_lose_roles(): void
    {
        $administrator = User::first();
        $this->putJson('/api/users/'.$administrator->id.'/roles', ['role_ids' => []])->assertUnprocessable();

        $this->assertTrue($administrator->fresh()->hasPermission('football.manage'));
    }

    public function test_last_administrator_role_cannot_be_disabled(): void
    {
        $role = Role::where('code', 'admin')->firstOrFail();
        $this->patchJson('/api/roles/'.$role->id, ['is_active' => false])->assertUnprocessable();

        $this->assertTrue($role->fresh()->is_active);
    }

    public function test_system_permission_rejects_numeric_false_and_renaming(): void
    {
        $permission = Permission::where('code', 'football.view')->firstOrFail();
        $this->patchJson('/api/permissions/'.$permission->id, ['is_active' => 0])->assertUnprocessable()->assertJsonPath('error.code', 'SYSTEM_PERMISSION');
        $this->patchJson('/api/permissions/'.$permission->id, ['code' => 'football.new'])->assertUnprocessable();

        $this->assertTrue($permission->fresh()->is_active);
        $this->assertSame('football.view', $permission->fresh()->code);
    }

    public function test_permission_name_can_be_updated_without_changing_system_code(): void
    {
        $permission = Permission::where('code', 'football.view')->firstOrFail();
        $this->patchJson('/api/permissions/'.$permission->id, ['code' => 'football.view', 'name' => 'Consultar fútbol'])->assertOk();
    }

    public function test_actor_cannot_grant_a_permission_they_do_not_have(): void
    {
        $actor = $this->limitedAdministrator();
        Passport::actingAs($actor);
        $role = Role::where('code', 'readonly')->firstOrFail();
        $permission = Permission::where('code', 'football.manage')->firstOrFail();

        $this->putJson('/api/roles/'.$role->id.'/permissions', ['permission_ids' => [$permission->id]])->assertForbidden();
        $this->assertFalse($role->fresh()->permissions->contains('code', 'football.manage'));
    }

    public function test_inactive_role_cannot_be_used_for_indirect_privilege_escalation(): void
    {
        $actor = $this->limitedAdministrator();
        $role = Role::create(['code' => 'inactive-football', 'name' => 'Inactive football', 'is_active' => false]);
        $role->permissions()->attach(Permission::where('code', 'football.manage')->firstOrFail());
        $actor->roles()->attach($role);
        Passport::actingAs($actor);

        $this->patchJson('/api/roles/'.$role->id, ['is_active' => true])->assertForbidden();
        $this->assertFalse($role->fresh()->is_active);
        $this->putJson('/api/users/'.$actor->id.'/roles', ['role_ids' => [$role->id]])->assertForbidden();
    }

    public function test_permissions_are_independent_and_reloaded_for_every_request(): void
    {
        $actor = $this->limitedAdministrator();
        Passport::actingAs($actor);
        $this->getJson('/api/competitions')->assertForbidden();
        $this->getJson('/api/users')->assertOk();
        $actor->roles()->detach();
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_user_password_is_hashed_and_not_returned(): void
    {
        $response = $this->postJson('/api/users', ['name' => 'Created', 'email' => 'created@test.local', 'password' => 'password-demo-123'])->assertCreated();

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertTrue(Hash::check('password-demo-123', User::where('email', 'created@test.local')->first()->password));
    }
}
