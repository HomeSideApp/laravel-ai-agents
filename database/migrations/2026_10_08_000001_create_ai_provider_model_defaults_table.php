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
     * The source of truth for the default model of a provider per routable
     * capability. One default per (provider, capability); ownership is
     * derived transitively through the model → provider chain, so no
     * tenant/user column lives here.
     */
    public function up(): void
    {
        Schema::create('ai_provider_model_defaults', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ai_provider_model_id')->constrained('ai_provider_models')->cascadeOnDelete();
            $table->string('capability', 32);
            $table->timestamps();

            $table->unique(['ai_provider_id', 'capability']);
            $table->index('ai_provider_model_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_provider_model_defaults');
    }
};
