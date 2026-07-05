<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_billing_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->uuid('plan_id')->nullable()->index();
            $table->string('provider')->default('fossbilling');
            $table->string('provider_client_id')->nullable()->index();
            $table->string('provider_subscription_id')->nullable()->index();
            $table->string('billing_status')->default('active')->index();
            $table->jsonb('limits');
            $table->jsonb('soft_thresholds');
            $table->jsonb('hard_thresholds');
            $table->timestampsTz();
        });

        Schema::create('usage_time_series', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('site_id')->index();
            $table->uuid('node_id')->index();
            $table->string('container_name')->nullable()->index();
            $table->decimal('cpu_percent', 8, 3)->default(0);
            $table->unsignedBigInteger('memory_bytes')->default(0);
            $table->unsignedBigInteger('disk_read_bytes')->default(0);
            $table->unsignedBigInteger('disk_write_bytes')->default(0);
            $table->unsignedBigInteger('disk_usage_bytes')->default(0);
            $table->unsignedBigInteger('network_rx_bytes')->default(0);
            $table->unsignedBigInteger('network_tx_bytes')->default(0);
            $table->unsignedBigInteger('request_count')->default(0);
            $table->decimal('latency_ms_p95', 10, 3)->default(0);
            $table->decimal('error_rate', 8, 5)->default(0);
            $table->timestampTz('sampled_at')->index();
            $table->timestampsTz();
        });

        Schema::create('tenant_usage_rollups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('window');
            $table->timestampTz('window_started_at')->index();
            $table->decimal('cpu_percent_avg', 10, 3)->default(0);
            $table->unsignedBigInteger('memory_bytes_max')->default(0);
            $table->unsignedBigInteger('disk_io_bytes_sum')->default(0);
            $table->unsignedBigInteger('disk_usage_bytes_max')->default(0);
            $table->unsignedBigInteger('bandwidth_bytes_sum')->default(0);
            $table->unsignedBigInteger('request_count_sum')->default(0);
            $table->unsignedInteger('active_sites')->default(0);
            $table->unsignedInteger('active_containers')->default(0);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'window', 'window_started_at']);
        });

        Schema::create('billing_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('provider')->default('fossbilling');
            $table->string('provider_event_id')->unique();
            $table->string('event_type')->index();
            $table->jsonb('payload');
            $table->string('status')->default('received')->index();
            $table->unsignedInteger('sequence')->default(0)->index();
            $table->timestampTz('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        Schema::create('billing_enforcement_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('site_id')->nullable()->index();
            $table->string('decision')->index();
            $table->string('severity')->index();
            $table->jsonb('violations');
            $table->jsonb('usage_snapshot');
            $table->jsonb('limits_snapshot');
            $table->string('status')->default('queued')->index();
            $table->string('idempotency_key')->unique();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_enforcement_decisions');
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('tenant_usage_rollups');
        Schema::dropIfExists('usage_time_series');
        Schema::dropIfExists('tenant_billing_profiles');
    }
};
