<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

use RuntimeException;

/**
 * Loads a labelled firewall corpus from JSONL or CSV.
 *
 * Supported formats (auto-detected by extension):
 * - .jsonl — one object per line: {"text": "...", "label": 1} where label
 *   is 1/0 or "injection"/"benign" (case-insensitive).
 * - .csv   — header row required; "text" and "label" columns.
 *
 * Malformed rows are skipped and counted, never fatal: a dirty public
 * dataset must not abort training.
 */
final class CorpusLoader
{
    /**
     * @return array{rows: list<array{text: string, label: float}>, skipped: int}
     *
     * @throws RuntimeException When the file does not exist or has an
     *                          unsupported extension.
     */
    public function load(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("The corpus file [{$path}] does not exist.");
        }

        $rawExtension = pathinfo($path, PATHINFO_EXTENSION);
        $extension = is_string($rawExtension) ? strtolower($rawExtension) : '';

        return match ($extension) {
            'jsonl', 'ndjson' => $this->loadJsonl($path),
            'csv', 'txt' => $this->loadCsv($path),
            default => throw new RuntimeException(
                "Unsupported corpus extension [{$extension}] for [{$path}]; use .jsonl or .csv.",
            ),
        };
    }

    /**
     * @return array{rows: list<array{text: string, label: float}>, skipped: int}
     */
    private function loadJsonl(string $path): array
    {
        $rows = [];
        $skipped = 0;

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("The corpus file [{$path}] could not be opened.");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                /** @var mixed $decoded */
                $decoded = json_decode($line, true, 16);

                if (! is_array($decoded)) {
                    $skipped++;

                    continue;
                }

                $row = $this->normalise($decoded['text'] ?? null, $decoded['label'] ?? null);

                if ($row === null) {
                    $skipped++;

                    continue;
                }

                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * @return array{rows: list<array{text: string, label: float}>, skipped: int}
     */
    private function loadCsv(string $path): array
    {
        $rows = [];
        $skipped = 0;

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("The corpus file [{$path}] could not be opened.");
        }

        try {
            $header = null;

            while (($record = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = array_map(
                        fn (mixed $column): string => strtolower(trim((string) $column)),
                        $record,
                    );

                    continue;
                }

                $values = array_combine($header, $record + array_fill(0, count($header), null));
                $row = $this->normalise($values['text'] ?? null, $values['label'] ?? null);

                if ($row === null) {
                    $skipped++;

                    continue;
                }

                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * Normalise one raw row; null when the row is unusable.
     *
     * @return array{text: string, label: float}|null
     */
    private function normalise(mixed $text, mixed $label): ?array
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $positive = [1, '1', true, 'injection', 'malicious', 'attack', 'jailbreak'];
        $negative = [0, '0', false, 'benign', 'legitimate', 'safe', 'normal'];

        if (in_array($label, $positive, true)) {
            $value = 1.0;
        } elseif (in_array($label, $negative, true)) {
            $value = 0.0;
        } else {
            return null;
        }

        return ['text' => $text, 'label' => $value];
    }
}
