<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hosting_plans', function (Blueprint $table): void {
            $table->jsonb('scheduler_policy')->nullable();
        });

        Schema::create('placement_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('site_id')->nullable()->index();
            $table->uuid('selected_node_id')->nullable()->index();
            $table->string('status')->index();
            $table->decimal('score', 10, 3)->default(0);
            $table->jsonb('requirements');
            $table->jsonb('decision');
            $table->timestampsTz();
        });

        Schema::create('usage_samples', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('node_id')->index();
            $table->unsignedInteger('cpu_millicores')->default(0);
            $table->unsignedInteger('memory_mb')->default(0);
            $table->unsignedBigInteger('io_read_bytes')->default(0);
            $table->unsignedBigInteger('io_write_bytes')->default(0);
            $table->unsignedBigInteger('network_rx_bytes')->default(0);
            $table->unsignedBigInteger('network_tx_bytes')->default(0);
            $table->unsignedBigInteger('requests')->default(0);
            $table->timestampTz('sampled_at')->index();
            $table->timestampsTz();
        });

        Schema::create('usage_aggregates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('bucket');
            $table->timestampTz('bucket_started_at')->index();
            $table->decimal('cpu_millicores_avg', 12, 3)->default(0);
            $table->unsignedInteger('memory_mb_max')->default(0);
            $table->unsignedBigInteger('io_read_bytes_sum')->default(0);
            $table->unsignedBigInteger('io_write_bytes_sum')->default(0);
            $table->unsignedBigInteger('network_rx_bytes_sum')->default(0);
            $table->unsignedBigInteger('network_tx_bytes_sum')->default(0);
            $table->unsignedBigInteger('requests_sum')->default(0);
            $table->timestampsTz();
            $table->unique(['site_id', 'bucket', 'bucket_started_at']);
        });

        Schema::create('enforcement_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('site_id')->index();
            $table->string('action')->index();
            $table->jsonb('reason');
            $table->string('status')->index();
            $table->timestampsTz();
        });

        Schema::create('failover_incidents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->index();
            $table->string('status')->index();
            $table->string('severity')->index();
            $table->timestampTz('detected_at')->index();
            $table->timestampTz('resolved_at')->nullable();
            $table->jsonb('resolution')->nullable();
            $table->timestampsTz();
        });

        Schema::create('storage_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('backend');
            $table->string('replication_mode');
            $table->jsonb('retention');
            $table->jsonb('targets');
            $table->string('status')->index();
            $table->timestampsTz();
        });

        Schema::create('storage_replication_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('policy_id')->index();
            $table->string('operation')->index();
            $table->string('status')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->jsonb('result')->nullable();
            $table->timestampsTz();
        });

        Schema::create('edge_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('site_id')->index();
            $table->uuid('origin_node_id')->index();
            $table->string('hostname')->index();
            $table->string('edge_pool')->index();
            $table->jsonb('routing_policy');
            $table->jsonb('health_policy');
            $table->string('status')->index();
            $table->timestampsTz();
            $table->unique(['site_id', 'hostname']);
        });

        Schema::create('security_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('severity')->index();
            $table->unsignedInteger('score')->index();
            $table->string('action')->index();
            $table->jsonb('signals');
            $table->string('request_fingerprint')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach ([
            'security_events',
            'edge_routes',
            'storage_replication_jobs',
            'storage_policies',
            'failover_incidents',
            'enforcement_actions',
            'usage_aggregates',
            'usage_samples',
            'placement_decisions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('hosting_plans', function (Blueprint $table): void {
            $table->dropColumn('scheduler_policy');
        });
    }
};

