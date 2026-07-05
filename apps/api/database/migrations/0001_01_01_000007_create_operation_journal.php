<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operation_journal', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('sequence')->nullable();
            $table->string('operation_name')->index();
            $table->string('category')->index();
            $table->string('source')->index();
            $table->string('entity_type')->index();
            $table->uuid('entity_id')->nullable()->index();
            $table->uuid('site_id')->nullable()->index();
            $table->uuid('node_id')->nullable()->index();
            $table->uuid('command_id')->nullable()->index();
            $table->string('correlation_id')->index();
            $table->string('causation_id')->nullable()->index();
            $table->string('idempotency_key')->unique();
            $table->uuid('actor_id')->nullable()->index();
            $table->jsonb('payload');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
            $table->index(['tenant_id', 'sequence']);
            $table->index(['entity_type', 'entity_id', 'occurred_at']);
        });

        Schema::create('operation_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('entity_type')->index();
            $table->uuid('entity_id')->index();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->jsonb('state');
            $table->string('checksum');
            $table->timestampsTz();
            $table->unique(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_snapshots');
        Schema::dropIfExists('operation_journal');
    }
};
