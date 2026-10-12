<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('user_role', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary([
                'user_id',
                'role_id',
            ]);
        });
        Schema::create('role_permission', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary([
                'role_id',
                'permission_id',
            ]);
        });
        Schema::create('areas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('external_id')->unique();
            $t->string('name');
            $t->string('code')->nullable();
            $t->string('flag_url', 500)->nullable();
            $t->unsignedBigInteger('parent_external_id')->nullable();
            $t->timestamps();
        });
        Schema::create('competitions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('external_id')->unique();
            $t->foreignId('area_id')->nullable()->constrained();
            $t->string('name');
            $t->string('code')->nullable();
            $t->string('type')->nullable();
            $t->string('emblem_url', 500)->nullable();
            $t->string('source_plan')->nullable();
            $t->unsignedBigInteger('current_season_id')->nullable();
            $t->unsignedBigInteger('active_snapshot_id')->nullable();
            $t->timestamp('source_updated_at')->nullable();
            $t->timestamp('catalog_fetched_at')->nullable();
            $t->timestamps();
        });
        Schema::create('competition_seasons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('external_id');
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->unsignedInteger('current_matchday')->nullable();
            $t->json('stages')->nullable();
            $t->unique([
                'competition_id',
                'external_id',
            ]);
            $t->timestamps();
        });
        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('external_id')->unique();
            $t->foreignId('area_id')->nullable()->constrained();
            $t->string('name');
            $t->string('short_name')->nullable();
            $t->string('tla', 10)->nullable();
            $t->string('crest_url', 500)->nullable();
            $t->timestamp('source_updated_at')->nullable();
            $t->timestamp('first_published_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('players', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('external_id')->unique();
            $t->string('name');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('nationality')->nullable();
            $t->timestamp('source_updated_at')->nullable();
            $t->timestamp('first_published_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('external_quota_pools', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('name')->unique();
            $t->unsignedInteger('max_requests')->default(10);
            $t->unsignedInteger('window_seconds')->default(60);
            $t->timestamp('cooldown_until')->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('external_credentials', function (Blueprint $t) {
            $t->id();
            $t->string('alias')->unique();
            $t->string('secret_reference')->unique();
            $t->foreignId('quota_pool_id')->constrained('external_quota_pools');
            $t->boolean('is_enabled')->default(true);
            $t->timestamp('paused_until')->nullable();
            $t->timestamps();
        });
        Schema::create('external_worker_configs', function (Blueprint $t) {
            $t->id();
            $t->string('worker_key')->unique();
            $t->foreignId('credential_id')->unique()->constrained('external_credentials');
            $t->unsignedInteger('max_requests')->default(10);
            $t->unsignedInteger('window_seconds')->default(60);
            $t->unsignedInteger('cooldown_seconds')->default(60);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('external_worker_runtime', function (Blueprint $t) {
            $t->id();
            $t->foreignId('worker_id')->unique()->constrained('external_worker_configs');
            $t->string('instance_id')->nullable();
            $t->timestamp('last_heartbeat_at')->nullable();
            $t->timestamp('paused_until')->nullable();
            $t->timestamp('instance_lease_until')->nullable();
            $t->timestamps();
        });
        Schema::create('imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('competition_id')->nullable()->constrained();
            $t->foreignId('season_id')->nullable()->constrained('competition_seasons');
            $t->string('type');
            $t->string('status')->default('pending');
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('retry_of_import_id')->nullable()->constrained('imports')->nullOnDelete();
            $t->string('active_scope_key')->nullable()->unique();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('error_code')->nullable();
            $t->text('error_message')->nullable();
            $t->json('quality_summary')->nullable();
            $t->timestamps();
        });
        Schema::create('import_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_id')->constrained('imports');
            $t->string('step_key');
            $t->string('status')->default('pending');
            $t->timestamp('completed_at')->nullable();
            $t->string('error_code')->nullable();
            $t->unique([
                'import_id',
                'step_key',
            ]);
            $t->timestamps();
        });
        Schema::create('competition_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('competition_id')->constrained();
            $t->foreignId('season_id')->constrained('competition_seasons');
            $t->foreignId('import_id')->unique()->constrained('imports');
            $t->string('build_status')->default('staging');
            $t->timestamp('published_at')->nullable();
            $t->timestamp('retired_at')->nullable();
            $t->json('quality_summary')->nullable();
            $t->timestamps();
        });
        Schema::create('snapshot_teams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('snapshot_id')->constrained('competition_snapshots')->cascadeOnDelete();
            $t->foreignId('team_id')->constrained('teams');
            $t->string('name');
            $t->string('short_name')->nullable();
            $t->string('crest_url', 500)->nullable();
            $t->json('metadata')->nullable();
            $t->unique([
                'snapshot_id',
                'team_id',
            ]);
        });
        Schema::create('snapshot_players', function (Blueprint $t) {
            $t->id();
            $t->foreignId('snapshot_team_id')->constrained('snapshot_teams')->cascadeOnDelete();
            $t->foreignId('player_id')->constrained('players');
            $t->string('name');
            $t->string('position')->nullable();
            $t->unsignedInteger('shirt_number')->nullable();
            $t->unique([
                'snapshot_team_id',
                'player_id',
            ]);
        });
        Schema::create('external_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_step_id')->constrained('import_steps');
            $t->string('request_key');
            $t->string('resource');
            $t->string('status')->default('pending');
            $t->foreignId('assigned_worker_id')->nullable()->constrained('external_worker_configs');
            $t->unsignedBigInteger('lease_version')->default(0);
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('not_before')->nullable();
            $t->foreignId('retry_of_task_id')->nullable()->constrained('external_tasks');
            $t->json('response_json')->nullable();
            $t->unique([
                'import_step_id',
                'request_key',
            ]);
            $t->index([
                'status',
                'not_before',
            ]);
            $t->timestamps();
        });
        Schema::create('external_task_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained('external_tasks');
            $t->unsignedTinyInteger('attempt_number');
            $t->foreignId('worker_id')->constrained('external_worker_configs');
            $t->foreignId('credential_id')->constrained('external_credentials');
            $t->timestamp('started_at');
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('result_status')->default('reserved');
            $t->unique([
                'task_id',
                'attempt_number',
            ]);
            $t->unique([
                'task_id',
                'worker_id',
            ]);
            $t->unique([
                'task_id',
                'credential_id',
            ]);
        });
        Schema::create('external_quota_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained('external_tasks');
            $t->foreignId('attempt_id')->unique()->constrained('external_task_attempts');
            $t->foreignId('worker_id')->constrained('external_worker_configs');
            $t->foreignId('quota_pool_id')->constrained('external_quota_pools');
            $t->timestamp('reserved_at');
            $t->string('reservation_status')->default('reserved');
            $t->index([
                'quota_pool_id',
                'reserved_at',
            ]);
        });
        Schema::table('competitions', function (Blueprint $t) {
            $t->foreign('current_season_id')->references('id')->on('competition_seasons')->nullOnDelete();
            $t->foreign('active_snapshot_id')->references('id')->on('competition_snapshots')->nullOnDelete();
        });
        Schema::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
        Schema::create('job_batches', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->integer('total_jobs');
            $t->integer('pending_jobs');
            $t->integer('failed_jobs');
            $t->longText('failed_job_ids');
            $t->mediumText('options')->nullable();
            $t->integer('cancelled_at')->nullable();
            $t->integer('created_at');
            $t->integer('finished_at')->nullable();
        });
        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        // Remove circular references before dropping seasons and snapshots.
        Schema::table('competitions', function (Blueprint $table) {
            $table->dropForeign(['current_season_id']);
            $table->dropForeign(['active_snapshot_id']);
        });

        foreach ([
            'password_reset_tokens',
            'sessions',
            'cache_locks',
            'cache',
            'job_batches',
            'failed_jobs',
            'jobs',
            'external_quota_reservations',
            'external_task_attempts',
            'external_tasks',
            'snapshot_players',
            'snapshot_teams',
            'competition_snapshots',
            'import_steps',
            'imports',
            'external_worker_runtime',
            'external_worker_configs',
            'external_credentials',
            'external_quota_pools',
            'players',
            'teams',
            'competition_seasons',
            'competitions',
            'areas',
            'role_permission',
            'user_role',
            'permissions',
            'roles',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
