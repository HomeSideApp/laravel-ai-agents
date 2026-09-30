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
     * Per-day, per-provider rollups: token totals, run counts and the
     * summed snapshot cost. Model-level detail stays in ai_runs until
     * retention consolidates it away; the daily table keeps one row per
     * (period, provider) so the unique key never involves NULLs.
     */
    public function up(): void
    {
        Schema::create('ai_usage_daily', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('period')->comment('UTC day this row consolidates');
            $table->foreignUuid('ai_provider_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider_name')->nullable();

            $table->unsignedBigInteger('runs')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cached_tokens')->default(0);
            $table->decimal('estimated_cost', 14, 6)->default(0);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['period', 'ai_provider_id']);
            $table->index('period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
    }
};
