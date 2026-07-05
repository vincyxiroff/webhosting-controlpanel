<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_port_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_id')->index();
            $table->uuid('site_id')->index();
            $table->unsignedInteger('internal_port');
            $table->unsignedInteger('host_port');
            $table->string('protocol')->default('tcp');
            $table->string('status')->default('allocated')->index();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['node_id', 'host_port', 'protocol', 'status']);
            $table->unique(['site_id', 'internal_port', 'protocol', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_port_allocations');
    }
};
