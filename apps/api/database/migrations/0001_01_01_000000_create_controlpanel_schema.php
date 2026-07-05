<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('name');
            $table->string('type');
            $table->string('status')->default('active');
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('role');
            $table->boolean('totp_enabled')->default(false);
            $table->jsonb('oauth_identities')->nullable();
            $table->timestampsTz();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->ipAddress('ip_address')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
        });

        Schema::create('hosting_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('tier')->index();
            $table->unsignedInteger('cpu_millicores');
            $table->unsignedInteger('memory_mb');
            $table->unsignedInteger('disk_mb');
            $table->jsonb('features');
            $table->jsonb('runtime_policy');
            $table->jsonb('billing_policy');
            $table->string('status')->default('draft');
            $table->timestampsTz();
        });

        Schema::create('nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->jsonb('roles');
            $table->string('region')->index();
            $table->string('status')->default('pending')->index();
            $table->boolean('draining')->default(false);
            $table->jsonb('labels')->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->jsonb('latest_metrics')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('node_registration_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->jsonb('roles');
            $table->string('region');
            $table->string('token_hash');
            $table->uuid('created_by');
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('plan_id')->index();
            $table->uuid('node_id')->nullable()->index();
            $table->string('name');
            $table->string('primary_domain')->index();
            $table->string('runtime');
            $table->string('runtime_version');
            $table->string('status')->index();
            $table->jsonb('repository')->nullable();
            $table->jsonb('environment')->nullable();
            $table->jsonb('quotas');
            $table->uuid('created_by');
            $table->timestampsTz();
        });

        Schema::create('domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('site_id')->nullable()->index();
            $table->string('name')->index();
            $table->string('type');
            $table->string('verification_status')->default('pending');
            $table->jsonb('dns_validation')->nullable();
            $table->timestampsTz();
        });

        Schema::create('vhost_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('site_id')->index();
            $table->jsonb('directives');
            $table->text('rendered_config');
            $table->string('status')->index();
            $table->uuid('created_by');
            $table->timestampsTz();
        });

        Schema::create('deployments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('status')->index();
            $table->string('commit_sha')->nullable();
            $table->jsonb('logs')->nullable();
            $table->timestampsTz();
        });

        Schema::create('ssl_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('provider');
            $table->string('status')->index();
            $table->jsonb('challenge')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('node_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->index();
            $table->string('command');
            $table->jsonb('payload');
            $table->string('status')->index();
            $table->string('idempotency_key')->unique();
            $table->jsonb('result')->nullable();
            $table->timestampsTz();
        });

        Schema::create('migration_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_node_id')->index();
            $table->uuid('target_node_id')->nullable()->index();
            $table->uuid('site_id')->nullable()->index();
            $table->string('status')->index();
            $table->jsonb('plan')->nullable();
            $table->timestampsTz();
        });

        Schema::create('billing_webhooks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('event')->index();
            $table->jsonb('payload');
            $table->string('status')->index();
            $table->timestampsTz();
        });

        Schema::create('domain_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('name')->index();
            $table->string('aggregate_type');
            $table->uuid('aggregate_id')->index();
            $table->jsonb('payload');
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('action')->index();
            $table->string('resource_type');
            $table->uuid('resource_id');
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->string('result');
            $table->timestampTz('created_at')->index();
        });
    }

    public function down(): void
    {
        foreach (array_reverse([
            'tenants', 'users', 'sessions', 'hosting_plans', 'nodes', 'node_registration_tokens',
            'sites', 'domains', 'vhost_revisions', 'deployments', 'ssl_orders', 'node_commands',
            'migration_jobs', 'billing_webhooks', 'domain_events', 'audit_logs',
        ]) as $table) {
            Schema::dropIfExists($table);
        }
    }
};

