<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add decision and execution columns to ai_action_proposals.
     *
     * This migration is additive and idempotent. Hosts running in `database`
     * isolation mode publish it to their tenant migration folder alongside
     * the rest of the scoped group (same naming pattern as the existing
     * 2026_01_01_100005_add_tenant_to_ai_action_proposals_table migration).
     *
     * Columns added:
     * - decided_by, decided_at, decision_note — decision audit trail
     * - original_payload — payload snapshot at creation (when amended)
     * - source_type, source_id — polymorphic origin
     * - ai_run_id — the AI run that generated the proposal
     * - executed_at, execution_result, execution_error — execution outcome
     */
    public function up(): void
    {
        if (! Schema::hasTable('ai_action_proposals')) {
            return;
        }

        Schema::table('ai_action_proposals', function (Blueprint $table): void {
            // Decision audit trail
            if (! Schema::hasColumn('ai_action_proposals', 'decided_by')) {
                $userModel = (string) config('ai-agents.user_model', 'App\\Models\\User');
                $table->foreignIdFor($userModel, 'decided_by')->nullable()->nullOnDelete();
            }
            if (! Schema::hasColumn('ai_action_proposals', 'decided_at')) {
                $table->timestamp('decided_at')->nullable();
            }
            if (! Schema::hasColumn('ai_action_proposals', 'decision_note')) {
                $table->text('decision_note')->nullable();
            }

            // Original payload snapshot
            if (! Schema::hasColumn('ai_action_proposals', 'original_payload')) {
                $table->json('original_payload')->nullable();
            }

            // Polymorphic source (nullableMorphs creates type+id columns + index)
            if (! Schema::hasColumn('ai_action_proposals', 'source_type')) {
                $table->nullableMorphs('source');
            }

            // AI run reference
            if (! Schema::hasColumn('ai_action_proposals', 'ai_run_id')) {
                $table->uuid('ai_run_id')->nullable();

                if (Schema::hasTable('ai_runs')) {
                    $table->foreign('ai_run_id')
                        ->references('id')
                        ->on('ai_runs')
                        ->nullOnDelete();
                }
            }

            // Execution outcome
            if (! Schema::hasColumn('ai_action_proposals', 'executed_at')) {
                $table->timestamp('executed_at')->nullable();
            }
            if (! Schema::hasColumn('ai_action_proposals', 'execution_result')) {
                $table->json('execution_result')->nullable();
            }
            if (! Schema::hasColumn('ai_action_proposals', 'execution_error')) {
                $table->text('execution_error')->nullable();
            }
        });

        // Add indices after columns exist (skip if nullableMorphs already created them)
        if (Schema::hasTable('ai_action_proposals')) {
            if (Schema::hasColumn('ai_action_proposals', 'decided_by')) {
                Schema::table('ai_action_proposals', function (Blueprint $table): void {
                    $table->index('decided_by');
                });
            }

            if (Schema::hasColumn('ai_action_proposals', 'ai_run_id')) {
                Schema::table('ai_action_proposals', function (Blueprint $table): void {
                    $table->index('ai_run_id');
                });
            }
        }
    }

    /**
     * Reverse the migration safely.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ai_action_proposals')) {
            return;
        }

        // Drop FKs and indices before columns
        if (Schema::hasColumn('ai_action_proposals', 'ai_run_id')) {
            Schema::table('ai_action_proposals', function (Blueprint $table): void {
                $table->dropForeign(['ai_run_id']);
                $table->dropIndex(['ai_run_id']);
            });
        }

        if (Schema::hasColumn('ai_action_proposals', 'decided_by')) {
            Schema::table('ai_action_proposals', function (Blueprint $table): void {
                $table->dropForeign(['decided_by']);
                $table->dropIndex(['decided_by']);
            });
        }

        // nullableMorphs creates an index named {field}_type_{field}_id_index
        // dropMorphs() removes the columns and their associated index.
        if (Schema::hasColumn('ai_action_proposals', 'source_type')
            && Schema::hasColumn('ai_action_proposals', 'source_id')) {
            Schema::table('ai_action_proposals', function (Blueprint $table): void {
                $table->dropMorphs('source');
            });
        }

        // Drop columns (safe: hasColumn check + DB::statement for SQLite compat)
        $columns = [
            'execution_error',
            'execution_result',
            'executed_at',
            'ai_run_id',
            'source_id',
            'source_type',
            'original_payload',
            'decision_note',
            'decided_at',
            'decided_by',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('ai_action_proposals', $column)) {
                DB::statement('ALTER TABLE ai_action_proposals DROP COLUMN '.$column);
            }
        }
    }
};
