<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Reference catalog of AI providers published by models.dev. This is
     * public metadata (what providers exist, where their API and docs live),
     * separate from ai_providers which holds host/user connection settings.
     */
    public function up(): void
    {
        Schema::create('models_dev_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('api_url')->nullable();
            $table->string('doc_url')->nullable();
            $table->json('env_vars')->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('models_dev_providers');
    }
};
