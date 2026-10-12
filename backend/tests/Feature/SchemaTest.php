<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    public function test_migrations_can_be_rolled_back_and_reapplied(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('competition_snapshots'));
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true]));
        $this->assertFalse(Schema::hasTable('competitions'));
        $this->assertFalse(Schema::hasTable('oauth_clients'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('external_quota_reservations'));
    }
}
