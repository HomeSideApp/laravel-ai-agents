<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the configured tenant foreign key to the ai_action_proposals table.
     *
     * The column type is inferred with foreignIdFor() from the configured
     * tenant model (integer or UUID tenant keys). Idempotent on purpose:
     * hosts whose ai_action_proposals table pre-dates the package (or whose
     * own migration already added the tenant column) are skipped instead of
     * failing on a duplicate column.
     */
    public function up(): void
    {
        $foreignKey = (string) config('ai-agents.tenant.foreign_key', 'tenant_id');
        $tenantModel = (string) config('ai-agents.tenant.model', 'App\\Models\\Team');

        if (! Schema::hasTable('ai_action_proposals')
            || Schema::hasColumn('ai_action_proposals', $foreignKey)) {
            return;
        }

        Schema::table('ai_action_proposals', function (Blueprint $table) use ($foreignKey, $tenantModel): void {
            $table->foreignIdFor($tenantModel, $foreignKey)->nullable()->nullOnDelete();

            $table->index($foreignKey);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $foreignKey = (string) config('ai-agents.tenant.foreign_key', 'tenant_id');

        if (! Schema::hasTable('ai_action_proposals')
            || ! Schema::hasColumn('ai_action_proposals', $foreignKey)) {
            return;
        }

        Schema::table('ai_action_proposals', function (Blueprint $table) use ($foreignKey): void {
            $table->dropForeign([$foreignKey]);
            $table->dropIndex([$foreignKey]);
            $table->dropColumn($foreignKey);
        });
    }
};
