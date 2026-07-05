<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('fingerprint')->nullable()->unique();
            $table->jsonb('runtime_support')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('health_status')->default('unknown')->index();
        });

        Schema::create('agent_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->index();
            $table->string('token_hash');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampsTz();
        });

        Schema::table('node_commands', function (Blueprint $table): void {
            $table->uuid('site_id')->nullable()->index();
            $table->string('type')->nullable()->index();
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestampTz('available_at')->nullable()->index();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('running_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('timeout_at')->nullable()->index();
            $table->text('last_error')->nullable();
        });

        Schema::create('dead_letter_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('command_id')->index();
            $table->uuid('node_id')->index();
            $table->uuid('site_id')->nullable()->index();
            $table->string('command');
            $table->jsonb('payload');
            $table->string('final_status');
            $table->text('error');
            $table->unsignedInteger('attempts');
            $table->timestampsTz();
        });

        Schema::create('node_heartbeats', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->index();
            $table->jsonb('metrics');
            $table->jsonb('containers');
            $table->jsonb('active_sites');
            $table->jsonb('health');
            $table->timestampTz('reported_at')->index();
            $table->timestampsTz();
        });

        Schema::create('site_actual_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->unique();
            $table->uuid('node_id')->index();
            $table->string('container_status')->index();
            $table->string('service_status')->index();
            $table->string('nginx_status')->index();
            $table->jsonb('runtime');
            $table->jsonb('drift')->nullable();
            $table->timestampTz('reported_at')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_actual_states');
        Schema::dropIfExists('node_heartbeats');
        Schema::dropIfExists('dead_letter_commands');

        Schema::table('node_commands', function (Blueprint $table): void {
            $table->dropColumn([
                'site_id',
                'type',
                'attempt',
                'max_attempts',
                'available_at',
                'sent_at',
                'acknowledged_at',
                'running_at',
                'finished_at',
                'timeout_at',
                'last_error',
            ]);
        });

        Schema::dropIfExists('agent_tokens');

        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['fingerprint', 'runtime_support', 'agent_version', 'health_status']);
        });
    }
};

