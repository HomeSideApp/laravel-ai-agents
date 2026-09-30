<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the configured tenant foreign key to the module_ai_configurations
     * table and widen the per-scope unique constraint to include it.
     *
     * The column type is inferred with foreignIdFor() from the configured
     * tenant model (integer or UUID tenant keys).
     */
    public function up(): void
    {
        $foreignKey = (string) config('ai-agents.tenant.foreign_key', 'tenant_id');
        $tenantModel = (string) config('ai-agents.tenant.model', 'App\\Models\\Team');

        Schema::table('module_ai_configurations', function (Blueprint $table) use ($foreignKey, $tenantModel): void {
            $table->foreignIdFor($tenantModel, $foreignKey)->nullable()->cascadeOnDelete();

            $table->dropUnique(['user_id', 'module', 'agent_name']);
            // Explicit short name: the generated identifier
            // (module_ai_configurations_household_id_user_id_module_agent_name_unique)
            // exceeds MySQL's 64-character identifier limit.
            $table->unique([$foreignKey, 'user_id', 'module', 'agent_name'], 'module_ai_configs_scope_unique');
            $table->index($foreignKey);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $foreignKey = (string) config('ai-agents.tenant.foreign_key', 'tenant_id');

        Schema::table('module_ai_configurations', function (Blueprint $table) use ($foreignKey): void {
            $table->dropForeign([$foreignKey]);
            $table->dropUnique('module_ai_configs_scope_unique');
            $table->dropIndex([$foreignKey]);
            $table->unique(['user_id', 'module', 'agent_name']);
            $table->dropColumn($foreignKey);
        });
    }
};
