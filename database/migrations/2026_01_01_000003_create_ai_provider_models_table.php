<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_provider_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_provider_id')->constrained()->cascadeOnDelete();

            $table->string('model');
            $table->string('display_name')->nullable();

            $table->boolean('enabled')->default(true);
            $table->boolean('is_default')->default(false);

            $table->json('capabilities_detected')->nullable();
            $table->json('capabilities_override')->nullable();

            $table->integer('context_window')->nullable();
            $table->integer('max_output_tokens')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('last_probed_at')->nullable();
            $table->string('last_probe_status')->nullable();

            $table->timestamps();

            $table->unique(['ai_provider_id', 'model']);
            $table->index(['ai_provider_id', 'enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_provider_models');
    }
};
