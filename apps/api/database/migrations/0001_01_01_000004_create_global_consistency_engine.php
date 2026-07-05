<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('desired_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->unique();
            $table->uuid('tenant_id')->index();
            $table->uuid('node_id')->index();
            $table->string('runtime_type');
            $table->string('runtime_version');
            $table->string('primary_domain');
            $table->jsonb('domains');
            $table->jsonb('environment_hashes');
            $table->jsonb('resource_limits');
            $table->string('container_config_hash')->index();
            $table->string('nginx_config_hash')->index();
            $table->string('ssl_state')->default('pending')->index();
            $table->string('status')->index();
            $table->unsignedBigInteger('generation')->default(1);
            $table->timestampTz('observed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('actual_state_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->index();
            $table->uuid('site_id')->nullable()->index();
            $table->jsonb('snapshot');
            $table->string('snapshot_hash')->index();
            $table->timestampTz('reported_at')->index();
            $table->timestampsTz();
        });

        Schema::create('drift_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('node_id')->index();
            $table->string('drift_type')->index();
            $table->string('severity')->index();
            $table->jsonb('expected');
            $table->jsonb('actual');
            $table->jsonb('actions');
            $table->string('status')->index();
            $table->timestampsTz();
        });

        Schema::create('reconciliation_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('node_id')->index();
            $table->uuid('drift_log_id')->nullable()->index();
            $table->string('status')->index();
            $table->string('idempotency_key')->unique();
            $table->jsonb('actions');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestampTz('available_at')->index();
            $table->timestampTz('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_jobs');
        Schema::dropIfExists('drift_logs');
        Schema::dropIfExists('actual_state_snapshots');
        Schema::dropIfExists('desired_states');
    }
};

