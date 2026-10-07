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
     * The durable transcript of a conversation, separate from ai_runs
     * (telemetry). The payload column holds the encrypted JSON turn
     * (content, attachments, steps, meta). user_id is mirrored for
     * envelope encryption and crypto-shredding without a join.
     */
    public function up(): void
    {
        $userModel = (string) config('ai-agents.user_model', 'App\\Models\\User');

        Schema::create('ai_conversation_messages', function (Blueprint $table) use ($userModel): void {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->foreignIdFor($userModel, 'user_id')->nullable()->nullOnDelete();
            $table->string('agent');
            $table->string('role', 25);
            // Encrypted turn payload (content, attachments, steps, meta).
            $table->longText('payload')->nullable();
            $table->unsignedInteger('payload_version')->default(1);
            $table->json('usage')->nullable();
            $table->string('status', 25);
            $table->timestamps();

            $table->foreign('conversation_id')
                ->references('id')
                ->on('ai_conversations')
                ->cascadeOnDelete();

            // Ordered recovery of a conversation's messages.
            $table->index(['conversation_id', 'id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_messages');
    }
};
