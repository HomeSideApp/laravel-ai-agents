<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the ai_action_proposals table (human-in-the-loop pattern).
     *
     * Idempotent on purpose: hosts that shipped their own copy of this table
     * before the package provided it (or published an early version of this
     * migration) keep their schema untouched instead of failing on a
     * duplicate create.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_action_proposals')) {
            return;
        }

        $userModel = (string) config('ai-agents.user_model', 'App\\Models\\User');

        Schema::create('ai_action_proposals', function (Blueprint $table) use ($userModel): void {
            $table->uuid('id')->primary();
            // The user who received the proposal — required: a proposal must
            // always have an owner who can accept or reject it.
            $table->foreignIdFor($userModel, 'user_id')->constrained()->cascadeOnDelete();
            $table->uuid('conversation_id')->nullable();
            // Host-defined proposal type (e.g. 'add_shopping_items'); no
            // enum on purpose so hosts declare domain-specific types.
            $table->string('type');
            $table->json('payload');
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')
                ->references('id')
                ->on('ai_conversations')
                ->nullOnDelete();

            $table->index(['user_id', 'status']);
            $table->index('conversation_id');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_action_proposals');
    }
};
