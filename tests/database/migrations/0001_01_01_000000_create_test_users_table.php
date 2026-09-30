<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create host-side tables the package's foreign keys and tenant
     * membership checks target.
     *
     * Deliberately integer-keyed: the suite must prove the package works
     * with auto-increment user ids, not only UUIDs. The extra household
     * columns mirror a host that renamed its tenant columns.
     */
    public function up(): void
    {
        Schema::create('test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('active_household_id')->nullable();
            $table->timestamps();
        });

        Schema::create('test_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('household_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('test_members');
        Schema::dropIfExists('test_users');
    }
};
