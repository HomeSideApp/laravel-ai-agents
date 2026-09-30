<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('module')->index();
            $table->string('label');
            $table->text('description')->nullable();
            $table->text('platform_prompt');
            $table->integer('prompt_version')->default(1);
            $table->boolean('enabled')->default(true);
            $table->json('parameters')->nullable();
            $table->timestamps();

            $table->index(['module', 'enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_agents');
    }
};
