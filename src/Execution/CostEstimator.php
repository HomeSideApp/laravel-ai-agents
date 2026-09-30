<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Execution;

use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiRun;
use HomeSide\AiAgents\Models\AiUsageDaily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Estimated-cost calculation, daily consolidation and retention for runs.
 *
 * Cost per run uses the provider's catalog-aligned pricing (USD per 1M
 * tokens) that AiProvider carries:
 *
 *   (input - cached)/1M * cost_input
 *   + cached/1M          * cost_cache_read
 *   + output/1M          * cost_output
 *
 * Snapshot discipline: ExecutionRecorder writes estimated_cost when the
 * result lands, so a run keeps the price in effect at run time even if
 * models.dev updates prices later.
 *
 * Consolidation: consolidateUpTo() rolls finished runs older than N days
 * into ai_usage_daily (one row per day+provider) and deletes them, keeping
 * the economical history while bounding the hot table. Queries over the
 * full horizon read both tables.
 */
class CostEstimator
{
    /** Default retention: runs older than 30 days are consolidated. */
    final public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Estimate the USD cost of one run from the provider's pricing.
     *
     * @param  int|null  $cachedTokens  Input tokens served from cache.
     * @param  string|float|null  $costInput  USD per 1M input tokens.
     * @param  string|float|null  $costOutput  USD per 1M output tokens.
     * @param  string|float|null  $costCacheRead  USD per 1M cached input tokens.
     * @return float|null Null when there are no tokens or no usable prices.
     */
    public function estimateFor(
        ?int $inputTokens,
        ?int $outputTokens,
        ?int $cachedTokens = null,
        string|float|null $costInput = null,
        string|float|null $costOutput = null,
        string|float|null $costCacheRead = null,
    ): ?float {
        if (($inputTokens === null || $inputTokens === 0)
            && ($outputTokens === null || $outputTokens === 0)
        ) {
            return null;
        }

        $inputCost = $costInput !== null ? (float) $costInput : null;
        $outputCost = $costOutput !== null ? (float) $costOutput : null;

        if ($inputCost === null && $outputCost === null) {
            return null;
        }

        $cached = max(0, (int) ($cachedTokens ?? 0));
        $input = (int) ($inputTokens ?? 0);

        $total = 0.0;

        if ($inputCost !== null) {
            if ($costCacheRead !== null) {
                // Split billing: cached tokens at the cache price, the rest
                // at the fresh-input price.
                $freshInput = max(0, $input - $cached);
                $total += ($freshInput / 1_000_000) * $inputCost;
                $total += ($cached / 1_000_000) * (float) $costCacheRead;
            } else {
                // No cache pricing published: every input token (cached or
                // not) bills at the standard input price.
                $total += ($input / 1_000_000) * $inputCost;
            }
        }

        if ($outputCost !== null) {
            $total += ((int) ($outputTokens ?? 0) / 1_000_000) * $outputCost;
        }

        return round($total, 6);
    }

    /**
     * Consolidate finished runs older than the retention window into
     * ai_usage_daily and delete them.
     *
     * Idempotent: the rollup is recomputed from the source rows for every
     * affected period, so re-running after a partial failure heals the
     * aggregates instead of double-counting.
     *
     * @param  int  $days  Consolidate runs whose created_at is older than
     *                     this many days (UTC).
     * @return array{periods: int, runs_consolidated: int}
     */
    public function consolidate(int $days = self::DEFAULT_RETENTION_DAYS): array
    {
        $cutoff = Carbon::now('UTC')->subDays(max(1, $days))->toDateString();

        // 1. Aggregate runs older than the cutoff, grouped by day+provider.
        // selectRaw introduces computed attributes invisible to static
        // analysis, hence the explicit object-shape annotations.
        /** @var Collection<int, object{period: string, provider_id: string|null, runs: int|string, input_tokens: int|string, output_tokens: int|string, cached_tokens: int|string, estimated_cost: string, unknown_token_runs: int|string, unknown_cost_runs: int|string}> $aggregates */
        $aggregates = AiRun::query()
            ->selectRaw('DATE(created_at) as period')
            ->selectRaw('provider_id')
            ->selectRaw('COUNT(*) as runs')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(cached_tokens), 0) as cached_tokens')
            ->selectRaw('COALESCE(SUM(estimated_cost), 0) as estimated_cost')
            ->selectRaw('SUM(CASE WHEN input_tokens IS NULL OR output_tokens IS NULL THEN 1 ELSE 0 END) as unknown_token_runs')
            ->selectRaw('SUM(CASE WHEN estimated_cost IS NULL THEN 1 ELSE 0 END) as unknown_cost_runs')
            ->where('status', '!=', 'running')
            ->whereDate('created_at', '<', $cutoff)
            ->groupBy(DB::raw('DATE(created_at)'), 'provider_id')
            ->get();

        if ($aggregates->isEmpty()) {
            return ['periods' => 0, 'runs_consolidated' => 0];
        }

        // 2. Upsert the daily rows (recompute totals, never accumulate).
        foreach ($aggregates as $aggregate) {
            AiUsageDaily::updateOrCreate(
                [
                    'period' => $aggregate->period,
                    'ai_provider_id' => $aggregate->provider_id,
                ],
                [
                    'provider_name' => $aggregate->provider_id !== null
                        ? $this->providerNameFor($aggregate->provider_id)
                        : null,
                    'runs' => (int) $aggregate->runs,
                    'input_tokens' => (int) $aggregate->input_tokens,
                    'output_tokens' => (int) $aggregate->output_tokens,
                    'cached_tokens' => (int) $aggregate->cached_tokens,
                    'estimated_cost' => (string) $aggregate->estimated_cost,
                    'unknown_token_runs' => (int) $aggregate->unknown_token_runs,
                    'unknown_cost_runs' => (int) $aggregate->unknown_cost_runs,
                    'last_synced_at' => Carbon::now(),
                ],
            );
        }

        // 3. Delete the source runs (same predicate as the aggregation).
        $deleted = AiRun::query()
            ->where('status', '!=', 'running')
            ->whereDate('created_at', '<', $cutoff)
            ->delete();

        Log::info('AI usage consolidation finished', [
            'cutoff' => $cutoff,
            'periods' => $aggregates->count(),
            'runs_deleted' => $deleted,
        ]);

        return [
            'periods' => $aggregates->count(),
            'runs_consolidated' => $deleted,
        ];
    }

    /**
     * Total spend over the full horizon: live runs plus consolidated rows.
     *
     * @param  string|null  $from  Inclusive UTC date.
     * @param  string|null  $to  Inclusive UTC date.
     * @return float Estimated USD spend.
     */
    public function totalSpend(?string $from = null, ?string $to = null): float
    {
        $consolidated = (float) AiUsageDaily::query()
            ->between($from, $to)
            ->sum('estimated_cost');

        $live = (float) AiRun::query()
            ->where('status', '!=', 'running')
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->sum('estimated_cost');

        return round($consolidated + $live, 6);
    }

    /**
     * Spend series grouped by UTC day across both tables — the shape for
     * spend-over-time charts. Periods present only in one source still
     * appear.
     *
     * @return array<string, float> Map of 'YYYY-MM-DD' => estimated USD.
     */
    public function spendByDay(?string $from = null, ?string $to = null): array
    {
        $series = [];

        /** @var Collection<int, object{period: string|Carbon, cost: string}> $daily */
        $daily = AiUsageDaily::query()
            ->between($from, $to)
            ->selectRaw('period')
            ->selectRaw('SUM(estimated_cost) as cost')
            ->groupBy('period')
            ->get();

        foreach ($daily as $row) {
            $key = $row->period instanceof Carbon
                ? $row->period->toDateString()
                : (string) $row->period;
            $series[$key] = ($series[$key] ?? 0) + (float) $row->cost;
        }

        /** @var Collection<int, object{period: string, cost: string}> $live */
        $live = AiRun::query()
            ->where('status', '!=', 'running')
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->selectRaw('DATE(created_at) as period')
            ->selectRaw('SUM(estimated_cost) as cost')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get();

        foreach ($live as $row) {
            $key = (string) $row->period;
            $series[$key] = ($series[$key] ?? 0) + (float) $row->cost;
        }

        ksort($series);

        return array_map(fn (float $v): float => round($v, 6), $series);
    }

    /**
     * Provider name for the consolidated row, best-effort.
     */
    private function providerNameFor(string $providerId): ?string
    {
        /** @var AiProvider|null $provider */
        $provider = AiProvider::query()
            ->where('id', $providerId)
            ->first();

        return $provider?->name;
    }
}
