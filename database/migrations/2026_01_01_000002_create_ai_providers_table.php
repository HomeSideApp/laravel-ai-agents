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
     * User foreign keys are created with foreignIdFor(), so the column type
     * matches the host's user model key (auto-increment integer or UUID).
     *
     * The columns mirroring the models.dev catalog (family, capabilities,
     * modalities, limits, costs) let a provider row carry the same data the
     * prefill suggestion offers, so the persisted configuration matches the
     * catalog shape 1:1.
     */
    public function up(): void
    {
        $userModel = (string) config('ai-agents.user_model', 'App\\Models\\User');

        Schema::create('ai_providers', function (Blueprint $table) use ($userModel) {
            $table->uuid('id')->primary();
            $table->foreignIdFor($userModel, 'user_id')->nullable()->nullOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('driver')->nullable();
            $table->string('base_url');
            $table->string('model');
            // Encrypted at rest by the model's `encrypted` cast; TEXT is
            // required because ciphertext is longer than the plaintext key.
            $table->text('api_key');
            $table->boolean('enabled')->default(true);
            $table->string('privacy_level')->default('unknown');
            $table->string('fallback_policy')->default('same_privacy_level');
            $table->string('module');

            // Catalog-aligned spec columns (mirror models_dev_models).
            $table->string('family')->nullable();
            $table->text('description')->nullable();

            $table->boolean('attachment')->default(false);
            $table->boolean('reasoning')->default(false);
            $table->json('reasoning_options')->nullable();
            $table->boolean('tool_call')->default(false);
            $table->boolean('structured_output')->default(false);
            $table->boolean('temperature')->default(true);
            $table->boolean('open_weights')->default(false);

            $table->json('modalities_input')->nullable();
            $table->json('modalities_output')->nullable();

            $table->integer('context_window')->nullable();
            $table->integer('max_input_tokens')->nullable();
            $table->integer('max_output_tokens')->nullable();

            // Cost per million tokens in USD.
            $table->decimal('cost_input', 12, 6)->nullable();
            $table->decimal('cost_output', 12, 6)->nullable();
            $table->decimal('cost_cache_read', 12, 6)->nullable();
            $table->decimal('cost_cache_write', 12, 6)->nullable();

            $table->json('configuration')->nullable();
            $table->foreignIdFor($userModel, 'created_by')->nullable()->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
