<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the configured tenant foreign key to the ai_providers table.
     *
     * The column type is inferred with foreignIdFor() from the configured
     * tenant model, so it matches integer or UUID tenant keys alike. Only
     * loaded when config('ai-agents.tenant.enabled') is true.
     */
    public function up(): void
    {
        $foreignKey = (string) config('ai-agents.tenant.foreign_key', 'tenant_id');
        $tenantModel = (string) config('ai-agents.tenant.model', 'App\\Models\\Team');

        Schema::table('ai_providers', function (Blueprint $table) use ($foreignKey, $tenantModel): void {
            $table->foreignIdFor($tenantModel, $foreignKey)->nullable()->nullOnDelete();

            $table->index([$foreignKey, 'enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $foreignKey = (string) config('ai-agents.tenant.foreign_key', 'tenant_id');

        Schema::table('ai_providers', function (Blueprint $table) use ($foreignKey): void {
            $table->dropForeign([$foreignKey]);
            $table->dropIndex([$foreignKey, 'enabled']);
            $table->dropColumn($foreignKey);
        });
    }
};
