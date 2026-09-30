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
     * The user foreign key is created with foreignIdFor(), so the column
     * type matches the host's user model key (integer or UUID).
     */
    public function up(): void
    {
        $userModel = (string) config('ai-agents.user_model', 'App\\Models\\User');

        Schema::create('module_ai_configurations', function (Blueprint $table) use ($userModel) {
            $table->uuid('id')->primary();
            $table->foreignIdFor($userModel, 'user_id')->nullable()->cascadeOnDelete();
            $table->foreignUuid('ai_provider_id')->nullable()->nullOnDelete();
            $table->uuid('provider_model_id')->nullable();
            $table->string('module');
            $table->string('agent_name');
            $table->string('label');
            $table->text('system_prompt');
            $table->text('instructions')->nullable();
            $table->text('additional_instructions')->nullable();
            $table->text('description')->nullable();
            $table->string('model')->nullable();
            $table->json('parameters')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'module', 'agent_name']);
            $table->index(['module', 'agent_name']);
        });

        Schema::table('module_ai_configurations', function (Blueprint $table) {
            $table->foreign('provider_model_id')->references('id')->on('ai_provider_models')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('module_ai_configurations', function (Blueprint $table) {
            $table->dropForeign(['provider_model_id']);
        });

        Schema::dropIfExists('module_ai_configurations');
    }
};
