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
     * One wrapped data-encryption key (DEK) per user. The DEK encrypts the
     * user's run content (user_message, reply); the wrapped form is the
     * DEK encrypted with the application key (envelope pattern), so
     * decryption requires the app but the DEK can be destroyed
     * (crypto-shredding) to irreversibly invalidate the user's history.
     */
    public function up(): void
    {
        $userModel = (string) config('ai-agents.user_model', 'App\\Models\\User');

        Schema::create('ai_user_content_keys', function (Blueprint $table) use ($userModel): void {
            $table->uuid('id')->primary();
            $table->foreignIdFor($userModel, 'user_id')->unique()->cascadeOnDelete();
            // The user's DEK, encrypted with the application key.
            $table->text('wrapped_dek');
            // Version for key rotation; ciphertext carries the version it
            // was written with.
            $table->unsignedInteger('key_version')->default(1);
            // Crypto-shredding marker: when set, the DEK value is destroyed
            // and every ciphertext of the user becomes unrecoverable.
            $table->timestamp('shredded_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_user_content_keys');
    }
};
