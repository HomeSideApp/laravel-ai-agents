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
        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ai_run_id');
            $table->string('tool');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status');
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->foreign('ai_run_id')->references('id')->on('ai_runs')->cascadeOnDelete();
            $table->index('ai_run_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_tool_calls');
    }
};
