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
     * Two pieces of embedding profile identity live on the model:
     *
     * - embedding_profile_version: an explicit escape hatch to invalidate the
     *   embedding space even when the visible configuration (model id,
     *   dimensions, endpoint) is unchanged (e.g. re-deployed weights behind
     *   the same alias). Never derived from updated_at.
     * - embedding_options: per-purpose provider options (generic/document/
     *   query). Credentials never live here; they belong to AiProvider.
     */
    public function up(): void
    {
        Schema::table('ai_provider_models', function (Blueprint $table): void {
            $table->unsignedInteger('embedding_profile_version')->default(1)->after('embedding_dimensions');
            $table->json('embedding_options')->nullable()->after('embedding_profile_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_provider_models', function (Blueprint $table): void {
            $table->dropColumn(['embedding_profile_version', 'embedding_options']);
        });
    }
};
