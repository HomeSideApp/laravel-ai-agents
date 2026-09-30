<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

/**
 * Contract for dataset column adapters.
 *
 * Public prompt-injection datasets ship different schemas (column names,
 * label vocabularies, row shapes). An adapter maps one dataset's fields
 * onto the canonical {text, label} rows the trainer consumes, so the
 * training pipeline never depends on a specific dataset's schema.
 *
 * Adapters live in resources/firewall/datasets/{name}.php and are plain
 * PHP files returning an array-shaped configuration:
 *
 *   return [
 *       'text' => 'prompt',            // column holding the text
 *       'label' => 'label',            // column holding the label
 *       'positive' => ['1', 'true'],   // label values meaning "injection"
 *       'negative' => ['0', 'false'],  // label values meaning "benign"
 *       'text_field' => null,          // alternative: nested key inside a JSON field
 *       'max_length' => 4000,          // drop rows whose text exceeds this
 *   ];
 */
final class DatasetAdapter
{
    /**
     * Load a dataset file (JSONL/CSV already resolved by CorpusLoader's
     * format detection is NOT used here — datasets come as raw JSONL/CSV
     * with arbitrary columns, so we read and map rows directly).
     *
     * @param  array<string, mixed>  $config  The adapter configuration.
     * @return array{rows: list<array{text: string, label: float}>, skipped: int}
     *
     * @throws \RuntimeException When the file is missing or the adapter is
     *                           misconfigured.
     */
    public function map(string $path, array $config): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException("The dataset file [{$path}] does not exist.");
        }

        $textColumn = is_string($config['text'] ?? null) ? $config['text'] : null;
        $labelColumn = is_string($config['label'] ?? null) ? $config['label'] : null;

        if ($textColumn === null || $labelColumn === null) {
            throw new \RuntimeException('The dataset adapter requires "text" and "label" column names.');
        }

        $positive = is_array($config['positive'] ?? null)
            ? array_values(array_map(strval(...), $config['positive']))
            : ['1', 'true', 'injection', 'malicious', 'attack'];
        $negative = is_array($config['negative'] ?? null)
            ? array_values(array_map(strval(...), $config['negative']))
            : ['0', 'false', 'benign', 'legitimate', 'safe'];
        $maxLength = is_int($config['max_length'] ?? null) ? $config['max_length'] : 4000;

        $rows = [];
        $skipped = 0;

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException("The dataset file [{$path}] could not be opened.");
        }

        try {
            $firstLine = true;
            $header = null;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $record = $this->decode($path, $line, $firstLine, $header);

                if ($record === null) {
                    $skipped++;

                    continue;
                }

                $row = $this->row($record, $textColumn, $labelColumn, $positive, $negative, $maxLength);

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
     * Decode one dataset line as JSONL or CSV (header sniffed on first row).
     *
     * @param  list<string>|null  $header
     *
     * @param-out  list<string>|null  $header
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $path, string $line, bool $firstLine, ?array &$header): ?array
    {
        $decoded = json_decode($line, true, 16);

        if (is_array($decoded)) {
            return $decoded;
        }

        // JSON decode failed: treat as CSV. The first line is the header.
        $columns = str_getcsv($line);

        if ($firstLine) {
            $header = array_map(
                fn (mixed $column): string => strtolower(trim((string) $column)),
                $columns,
            );

            return null;
        }

        if ($header === null) {
            return null;
        }

        $values = array_combine($header, $columns + array_fill(0, count($header), null));

        return $values;
    }

    /**
     * Map one decoded record onto a canonical row.
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $positive
     * @param  list<string>  $negative
     * @return array{text: string, label: float}|null
     */
    private function row(
        array $record,
        string $textColumn,
        string $labelColumn,
        array $positive,
        array $negative,
        int $maxLength,
    ): ?array {
        $text = $record[$textColumn] ?? null;
        $label = $record[$labelColumn] ?? null;

        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $maxLength) {
            return null;
        }

        $labelKey = is_scalar($label) ? strtolower(trim((string) $label)) : '';

        if (in_array($labelKey, $positive, true)) {
            $value = 1.0;
        } elseif (in_array($labelKey, $negative, true)) {
            $value = 0.0;
        } else {
            return null;
        }

        return ['text' => $text, 'label' => $value];
    }
}
