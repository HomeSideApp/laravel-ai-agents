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
     * Reference catalog of AI models published by models.dev: specifications,
     * pricing, limits and capability flags per model. Costs are stored as
     * decimal(12,6) because per-token prices go down to 0.0000xx for cheap
     * providers.
     */
    public function up(): void
    {
        Schema::create('models_dev_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('models_dev_provider_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('model_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('family')->nullable();

            // Capability flags from the API (attachment, reasoning, ...).
            $table->boolean('attachment')->default(false);
            $table->boolean('reasoning')->default(false);
            $table->json('reasoning_options')->nullable();
            $table->boolean('tool_call')->default(false);
            $table->boolean('structured_output')->default(false);
            $table->boolean('temperature')->default(true);
            $table->boolean('open_weights')->default(false);

            $table->date('release_date')->nullable();
            $table->date('last_updated')->nullable();

            $table->json('modalities_input')->nullable();
            $table->json('modalities_output')->nullable();

            $table->integer('context_window')->nullable();
            $table->integer('max_input_tokens')->nullable();
            $table->integer('max_output_tokens')->nullable();

            // Cost per million tokens in USD (models.dev convention).
            $table->decimal('cost_input', 12, 6)->nullable();
            $table->decimal('cost_output', 12, 6)->nullable();
            $table->decimal('cost_cache_read', 12, 6)->nullable();
            $table->decimal('cost_cache_write', 12, 6)->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['models_dev_provider_id', 'model_id']);
            $table->index('family');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('models_dev_models');
    }
};
