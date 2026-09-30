<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Synchronizer;

use HomeSide\AiAgents\AgentRegistry;
use HomeSide\AiAgents\Contracts\AgentMetadata;
use HomeSide\AiAgents\Contracts\DomainAgent;
use HomeSide\AiAgents\Models\AiAgent;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;

/**
 * Idempotent synchroniser between AgentRegistry classes and the ai_agents table.
 *
 * On each run it:
 * 1. Discovers every registered DomainAgent.
 * 2. Creates missing ai_agents rows (with the class's default prompt as platform_prompt).
 * 3. Flags rows whose key no longer has a matching class.
 * 4. Flags classes that have no corresponding row.
 *
 * The synchroniser never deletes rows — it marks them for review.
 */
class AgentSynchronizer
{
    public function __construct(
        private readonly AgentRegistry $registry,
    ) {}

    /**
     * Synchronise all registered agents with the database.
     */
    public function sync(): SyncResultData
    {
        $registeredKeys = $this->registry->all();
        $existingRows = AiAgent::query()->get()->keyBy('key');

        $created = 0;
        $unchanged = 0;
        $orphanedKeys = [];
        $missingRows = [];

        // 1. Create or verify rows for every registered agent.
        foreach ($registeredKeys as $key) {
            $class = $this->registry->getClass($key);

            if ($class === null) {
                continue;
            }

            $agent = $this->registry->get($key);
            $row = $existingRows->get($key);
            $classPrompt = $agent instanceof Agent ? (string) $agent->instructions() : '';

            if ($row === null) {
                // Create a new row using the class's default prompt.
                $label = $agent instanceof AgentMetadata
                    ? $agent->label()
                    : $this->humanizeKey($key);

                $description = $agent instanceof AgentMetadata
                    ? $agent->description()
                    : null;

                $parameters = $agent instanceof AgentMetadata
                    ? $agent->defaultParameters()
                    : null;

                AiAgent::create([
                    'key' => $key,
                    'module' => $agent->module(),
                    'label' => $label,
                    'description' => $description,
                    'platform_prompt' => $classPrompt,
                    'seeded_prompt_hash' => hash('sha256', $classPrompt),
                    'prompt_version' => 1,
                    'enabled' => true,
                    'parameters' => $parameters,
                ]);

                $created++;
            } else {
                $currentHash = hash('sha256', $row->platform_prompt);

                if ($row->seeded_prompt_hash === null && $row->platform_prompt === $classPrompt) {
                    $row->update(['seeded_prompt_hash' => $currentHash]);
                } elseif ($row->seeded_prompt_hash !== null
                    && hash_equals($row->seeded_prompt_hash, $currentHash)
                    && $row->platform_prompt !== $classPrompt) {
                    $row->update([
                        'platform_prompt' => $classPrompt,
                        'seeded_prompt_hash' => hash('sha256', $classPrompt),
                        'prompt_version' => $row->prompt_version + 1,
                    ]);
                }

                $unchanged++;
            }
        }

        // 2. Find orphaned rows (in DB but not in registry).
        foreach ($existingRows as $key => $row) {
            if (! in_array($key, $registeredKeys, true)) {
                $orphanedKeys[] = $key;

                Log::warning('AiAgent row has no matching registered class', [
                    'key' => $key,
                    'ai_agent_id' => $row->id,
                ]);
            }
        }

        // 3. After step 1, all registered keys should have rows.
        // Check the database to confirm (not the stale snapshot).
        $finalKeys = AiAgent::query()->pluck('key')->toArray();

        foreach ($registeredKeys as $key) {
            if (! in_array($key, $finalKeys, true)) {
                $missingRows[] = $key;
            }
        }

        return new SyncResultData(
            created: $created,
            unchanged: $unchanged,
            orphanedKeys: $orphanedKeys,
            missingRows: $missingRows,
        );
    }

    /**
     * Detect inconsistencies between registered classes and database rows.
     */
    public function diagnose(): DiagnoseResultData
    {
        $registeredKeys = $this->registry->all();
        $existingKeys = AiAgent::query()->pluck('key', 'key')->toArray();

        $classesWithoutRows = [];
        $rowsWithoutClasses = [];

        foreach ($registeredKeys as $key) {
            if (! isset($existingKeys[$key])) {
                $classesWithoutRows[$key] = $this->registry->getClass($key) ?? 'unknown';
            }
        }

        foreach (array_keys($existingKeys) as $key) {
            if (! in_array($key, $registeredKeys, true)) {
                $orphanRow = AiAgent::findByKey($key);
                $rowsWithoutClasses[$key] = $orphanRow !== null ? $orphanRow->label : 'unknown';
            }
        }

        return new DiagnoseResultData(
            classesWithoutRows: $classesWithoutRows,
            rowsWithoutClasses: $rowsWithoutClasses,
        );
    }

    /**
     * Convert a snake_case key to a human-readable label.
     */
    private function humanizeKey(string $key): string
    {
        return str(str_replace('.', ' ', $key))->title()->toString();
    }
}
