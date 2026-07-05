<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_state_machines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('site_id')->unique();
            $table->string('state')->index();
            $table->string('source')->index();
            $table->unsignedInteger('priority')->index();
            $table->unsignedBigInteger('version')->default(1);
            $table->string('last_idempotency_key')->nullable()->index();
            $table->jsonb('context')->nullable();
            $table->timestampsTz();
        });

        Schema::create('state_transition_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('site_id')->index();
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->string('source')->index();
            $table->unsignedInteger('priority')->index();
            $table->string('idempotency_key')->index();
            $table->jsonb('context')->nullable();
            $table->string('result')->index();
            $table->text('message')->nullable();
            $table->timestampsTz();
        });

        Schema::create('tenant_event_sequences', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->unsignedBigInteger('next_sequence')->default(1);
            $table->timestampsTz();
        });

        Schema::create('ordered_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type')->index();
            $table->string('source')->index();
            $table->string('idempotency_key')->unique();
            $table->jsonb('payload');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'sequence']);
        });

        Schema::create('conflict_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('site_id')->nullable()->index();
            $table->string('winner_source')->index();
            $table->string('loser_source')->index();
            $table->string('winner_action');
            $table->string('loser_action');
            $table->jsonb('resolution');
            $table->string('status')->index();
            $table->timestampsTz();
        });

        Schema::create('distributed_lock_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('lock_key')->index();
            $table->string('owner')->index();
            $table->string('operation')->index();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributed_lock_audits');
        Schema::dropIfExists('conflict_logs');
        Schema::dropIfExists('ordered_events');
        Schema::dropIfExists('tenant_event_sequences');
        Schema::dropIfExists('state_transition_logs');
        Schema::dropIfExists('site_state_machines');
    }
};

