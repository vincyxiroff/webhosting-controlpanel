<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_runtime_objects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->unique();
            $table->uuid('node_id')->index();
            $table->string('container_id')->nullable()->index();
            $table->string('container_name')->nullable();
            $table->string('network_id')->nullable();
            $table->string('network_name')->nullable();
            $table->string('volume_id')->nullable();
            $table->string('volume_name')->nullable();
            $table->string('runtime_type')->index();
            $table->string('runtime_version')->nullable();
            $table->string('nginx_config_path')->nullable();
            $table->string('nginx_config_version')->nullable();
            $table->jsonb('resource_limits')->nullable();
            $table->jsonb('health')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_runtime_objects');
    }
};

