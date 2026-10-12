<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Player;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AclApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function person(string $role): User
    {
        $u = User::create(['name' => 'Prueba', 'email' => $role.'@test.local', 'password' => 'password-demo-123', 'is_active' => true]);
        $u->roles()->attach(Role::where('code', $role)->firstOrFail());

        return $u;
    }

    public function test_api_rechaza_anonimo(): void
    {
        $this->getJson('/api/team')->assertStatus(401);
    }

    public function test_api_opened_without_json_header_also_returns_401_json(): void
    {
        $this->get('/api/competitions/2013')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_readonly_no_puede_importar_ni_administrar(): void
    {
        Passport::actingAs($this->person('readonly'));
        $c = Competition::create(['external_id' => 2016, 'name' => 'Championship', 'code' => 'ELC', 'type' => 'LEAGUE']);
        $this->getJson('/api/competitions/'.$c->external_id)->assertOk()->assertJsonPath('data.competition.import_status', 'not_imported');
        $this->postJson('/api/competitions/'.$c->external_id.'/refresh')->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_admin_inicia_importacion_asincrona_sin_crear_duplicados(): void
    {
        Queue::fake();
        Passport::actingAs($this->person('admin'));
        $c = Competition::create(['external_id' => 2016, 'name' => 'Championship', 'code' => 'ELC', 'type' => 'LEAGUE']);
        $first = $this->getJson('/api/competitions/2016')->assertStatus(202)->json('data.import.id');
        $second = $this->getJson('/api/competitions/2016')->assertStatus(202)->json('data.import.id');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('imports', 1);
    }

    public function test_login_invalido(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'nadie@example.test', 'password' => 'incorrecta'])->assertStatus(401);
    }

    public function test_listas_no_incluyen_staging(): void
    {
        Passport::actingAs($this->person('readonly'));
        Team::create(['external_id' => 17, 'name' => 'En preparación']);
        Player::create(['external_id' => 88, 'name' => 'Sin publicar']);
        $this->getJson('/api/team')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/players')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
