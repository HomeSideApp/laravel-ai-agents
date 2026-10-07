<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backfill one Text default per provider from the legacy signals, so
     * existing installations keep resolving a text model after is_default
     * stops being the source of truth:
     *
     * 1. the model flagged is_default = true, when present;
     * 2. otherwise the model whose `model` matches AiProvider.model.
     *
     * No Embeddings default is created automatically: embeddings require
     * explicit per-model support and must be configured deliberately.
     */
    public function up(): void
    {
        $providers = DB::table('ai_providers')->select(['id', 'model'])->get();

        foreach ($providers as $provider) {
            if (DB::table('ai_provider_model_defaults')
                ->where('ai_provider_id', $provider->id)
                ->where('capability', 'text')
                ->exists()) {
                continue;
            }

            $modelId = DB::table('ai_provider_models')
                ->where('ai_provider_id', $provider->id)
                ->where('is_default', true)
                ->value('id');

            if ($modelId === null && is_string($provider->model) && $provider->model !== '') {
                $modelId = DB::table('ai_provider_models')
                    ->where('ai_provider_id', $provider->id)
                    ->where('model', $provider->model)
                    ->value('id');
            }

            if ($modelId === null) {
                continue;
            }

            DB::table('ai_provider_model_defaults')->insert([
                'id' => (string) Str::uuid7(),
                'ai_provider_id' => $provider->id,
                'ai_provider_model_id' => $modelId,
                'capability' => 'text',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ai_provider_model_defaults')->where('capability', 'text')->delete();
    }
};
