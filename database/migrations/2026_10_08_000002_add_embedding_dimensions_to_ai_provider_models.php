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
     * Embedding vector length is a critical value (vector stores, result
     * validation, future re-indexing), so it lives in its own column
     * instead of buried in metadata.
     */
    public function up(): void
    {
        Schema::table('ai_provider_models', function (Blueprint $table): void {
            $table->integer('embedding_dimensions')->nullable()->after('max_output_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_provider_models', function (Blueprint $table): void {
            $table->dropColumn('embedding_dimensions');
        });
    }
};
