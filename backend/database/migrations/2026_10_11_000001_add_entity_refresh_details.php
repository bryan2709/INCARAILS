<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['teams', 'players'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->json('source_profile')->nullable();
                $table->timestamp('source_fetched_at')->nullable();
            });
        }
        Schema::table('imports', function (Blueprint $table): void {
            $table->foreignId('team_id')->nullable()->constrained('teams');
            $table->foreignId('player_id')->nullable()->constrained('players');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('player_id');
        });
        foreach (['teams', 'players'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['source_profile', 'source_fetched_at']));
        }
    }
};
