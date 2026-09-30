<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_daily', function (Blueprint $table): void {
            $table->unsignedBigInteger('unknown_token_runs')->default(0);
            $table->unsignedBigInteger('unknown_cost_runs')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_daily', function (Blueprint $table): void {
            $table->dropColumn(['unknown_token_runs', 'unknown_cost_runs']);
        });
    }
};
