<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('module');
            $table->string('default_scope_key')->nullable();
            $table->timestamps();

            $table->unique(['ai_provider_id', 'module']);
            $table->unique(['module', 'default_scope_key']);
            $table->index('module');
        });

        $tenantKey = (bool) config('ai-agents.tenant.enabled', false)
            ? (string) config('ai-agents.tenant.foreign_key', 'tenant_id')
            : null;

        DB::table('ai_providers')->orderBy('created_at')->orderBy('id')->chunk(100, function ($providers) use ($tenantKey): void {
            foreach ($providers as $provider) {
                $scope = $provider->user_id !== null
                    ? 'user:'.$provider->user_id
                    : ($tenantKey !== null && $provider->{$tenantKey} !== null
                        ? 'tenant:'.$provider->{$tenantKey}
                        : 'system');

                $hasDefault = DB::table('ai_provider_modules')
                    ->where('module', $provider->module)
                    ->where('default_scope_key', $scope)
                    ->exists();

                DB::table('ai_provider_modules')->insertOrIgnore([
                    'ai_provider_id' => $provider->id,
                    'module' => $provider->module,
                    'default_scope_key' => $provider->is_default && ! $hasDefault ? $scope : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_modules');
    }
};
