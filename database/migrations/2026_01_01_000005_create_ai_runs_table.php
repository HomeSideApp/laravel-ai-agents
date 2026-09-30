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

        Schema::create('ai_runs', function (Blueprint $table) use ($userModel) {
            $table->uuid('id')->primary();
            $table->foreignIdFor($userModel, 'user_id')->nullable()->nullOnDelete();
            $table->uuid('conversation_id')->nullable();
            $table->string('agent');
            $table->unsignedInteger('agent_version')->default(1);
            $table->uuid('provider_id')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('model_name')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cached_tokens')->nullable();
            // Estimated USD cost, snapshotted by ExecutionRecorder at
            // result time with the provider's pricing then in effect.
            $table->decimal('estimated_cost', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('status'); // ok/error/cancelled
            $table->string('error_code')->nullable();
            $table->text('user_message')->nullable();
            $table->text('reply')->nullable();
            // Content storage state (ContentMode): how user_message/reply
            // are stored — encrypted (default), plain (consented), redacted
            // or none per the retention resolver's decision.
            $table->string('content_mode')->default('encrypted');
            // Support-grant bookkeeping: the user's formal consent to share
            // these texts with support. Content never becomes plaintext on
            // disk; grants authorise on-the-fly decryption only.
            $table->timestamp('content_granted_at')->nullable();
            $table->foreignIdFor($userModel, 'content_granted_by')->nullable()->nullOnDelete();
            $table->string('content_grant_reference')->nullable();
            $table->timestamp('content_grant_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('agent');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
