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

        Schema::create('ai_conversations', function (Blueprint $table) use ($userModel) {
            $table->uuid('id')->primary();
            $table->foreignIdFor($userModel, 'user_id')->constrained()->cascadeOnDelete();
            $table->string('agent');
            $table->string('title')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('agent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
