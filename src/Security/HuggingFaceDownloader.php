<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Security;

use RuntimeException;

/**
 * Downloads HuggingFace datasets via the datasets-server REST API.
 *
 * Fetches rows in batches and writes them as JSONL, so the training
 * pipeline can consume them directly with CorpusLoader or DatasetAdapter.
 *
 * The API is public for non-gated datasets; no token is required.
 * Rate-limit is generous (100 req/min); we use a modest batch size.
 */
final class HuggingFaceDownloader
{
    private const API_BASE = 'https://datasets-server.huggingface.co';

    private const BATCH_SIZE = 100;

    /**
     * Download a dataset split and write it as JSONL.
     *
     * @return array{path: string, rows: int, features: list<string>}
     *
     * @throws RuntimeException When the download fails or the dataset is not found.
     */
    public function download(
        string $datasetId,
        string $config = 'default',
        string $split = 'train',
        ?string $outPath = null,
    ): array {
        $outPath ??= storage_path("app/private/firewall-datasets/{$this->slugify($datasetId)}-{$split}.jsonl");

        $dir = dirname($outPath);

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create directory [{$dir}].");
        }

        $handle = fopen($outPath, 'w');

        if ($handle === false) {
            throw new RuntimeException("Could not open [{$outPath}] for writing.");
        }

        try {
            $total = $this->fetchRowCount($datasetId, $config, $split);
            $offset = 0;
            $written = 0;
            $features = [];

            $this->line("Downloading {$datasetId} (config={$config}, split={$split}): {$total} rows...");

            while ($offset < $total) {
                $batch = $this->fetchBatch($datasetId, $config, $split, $offset);

                if ($batch === []) {
                    break;
                }

                if ($features === []) {
                    $features = $batch['features'];
                }

                foreach ($batch['rows'] as $row) {
                    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    if ($json !== false) {
                        fwrite($handle, $json."\n");
                        $written++;
                    }
                }

                $offset += self::BATCH_SIZE;

                $this->line("  ... {$written}/{$total} rows");
            }
        } finally {
            fclose($handle);
        }

        return ['path' => $outPath, 'rows' => $written, 'features' => $features];
    }

    /**
     * Get dataset metadata from the HuggingFace API.
     *
     * @return array{id: string, license: string, features: list<string>, configs: list<string>, tags: list<string>}
     */
    public function info(string $datasetId): array
    {
        $url = "https://huggingface.co/api/datasets/{$datasetId}";
        $json = $this->fetch($url);

        /** @var array<string, mixed>|null $data */
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new RuntimeException("Could not fetch metadata for [{$datasetId}].");
        }

        $cardData = $data['cardData'] ?? [];

        $configs = [];

        foreach ($cardData['configs'] ?? [] as $cfg) {
            $name = $cfg['config_name'] ?? null;

            if (is_string($name)) {
                $configs[] = $name;
            }
        }

        $features = [];

        if ($configs !== []) {
            $firstConfig = $configs[0];

            try {
                $rowsData = $this->fetchBatch($datasetId, $firstConfig, 'train', 0);
                $features = $rowsData['features'];
            } catch (\Throwable) {
                // Feature detection best-effort.
            }
        }

        return [
            'id' => $datasetId,
            'license' => (string) ($cardData['license'] ?? $data['license'] ?? 'unknown'),
            'features' => $features,
            'configs' => $configs,
            'tags' => $data['tags'] ?? [],
        ];
    }

    /**
     * Get the total row count for a split.
     */
    private function fetchRowCount(string $datasetId, string $config, string $split): int
    {
        $url = self::API_BASE."/rows?dataset={$datasetId}&config={$config}&split={$split}&offset=0&length=1";
        $json = $this->fetch($url);
        $data = json_decode($json, true);

        return is_array($data) ? (int) ($data['num_rows_total'] ?? 0) : 0;
    }

    /**
     * Fetch a batch of rows.
     *
     * @return array{rows: list<array<string, mixed>>, features: list<string>}
     */
    private function fetchBatch(string $datasetId, string $config, string $split, int $offset): array
    {
        $url = self::API_BASE."/rows?dataset={$datasetId}&config={$config}&split={$split}&offset={$offset}&length=".self::BATCH_SIZE;
        $json = $this->fetch($url);
        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['rows'])) {
            return ['rows' => [], 'features' => []];
        }

        $features = [];

        if (isset($data['features']) && is_array($data['features'])) {
            foreach ($data['features'] as $f) {
                if (isset($f['name'])) {
                    $features[] = (string) $f['name'];
                }
            }
        }

        $rows = [];

        foreach ($data['rows'] as $item) {
            if (isset($item['row']) && is_array($item['row'])) {
                $rows[] = $item['row'];
            }
        }

        return ['rows' => $rows, 'features' => $features];
    }

    /**
     * Fetch a URL with error handling.
     */
    private function fetch(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        /** @var string|false $body */
        $body = file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException("HTTP request failed for [{$url}].");
        }

        return $body;
    }

    private function slugify(string $datasetId): string
    {
        return strtolower(str_replace('/', '-', $datasetId));
    }

    private function line(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message."\n");
        }
    }
}
