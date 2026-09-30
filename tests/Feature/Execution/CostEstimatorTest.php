<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Feature\Execution;

use HomeSide\AiAgents\Execution\CostEstimator;
use HomeSide\AiAgents\Models\AiProvider;
use HomeSide\AiAgents\Models\AiRun;
use HomeSide\AiAgents\Models\AiUsageDaily;
use HomeSide\AiAgents\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * CostEstimator: per-run cost estimate from catalog-aligned pricing,
 * consolidation of old runs into ai_usage_daily and spend aggregation
 * across both tables.
 */
final class CostEstimatorTest extends TestCase
{
    public function test_estimate_computes_fresh_cached_and_output_costs(): void
    {
        $cost = app(CostEstimator::class)->estimateFor(
            inputTokens: 1_000_000,
            outputTokens: 500_000,
            cachedTokens: 200_000,
            costInput: '2.0',
            costOutput: '8.0',
            costCacheRead: '0.5',
        );

        // 800k fresh * 2.0/1M + 200k cached * 0.5/1M + 500k out * 8.0/1M
        $this->assertSame(1.6 + 0.1 + 4.0, $cost);
    }

    public function test_estimate_without_cache_read_prices_cached_as_fresh(): void
    {
        $cost = app(CostEstimator::class)->estimateFor(
            inputTokens: 1_000_000,
            outputTokens: 0,
            cachedTokens: 400_000,
            costInput: '3.0',
            costOutput: null,
            costCacheRead: null,
        );

        // All 1M input billed at input price: 3.0.
        $this->assertSame(3.0, $cost);
    }

    public function test_estimate_returns_null_without_tokens_or_prices(): void
    {
        $estimator = app(CostEstimator::class);

        $this->assertNull($estimator->estimateFor(null, null, null, '2.0', '8.0'));
        $this->assertNull($estimator->estimateFor(1000, 500, null, null, null));
    }

    /**
     * Insert a run with a backdated created_at (not mass-assignable via
     * fillable, so tests write it explicitly).
     */
    private function makeRun(AiProvider $provider, array $attributes): AiRun
    {
        $run = AiRun::create([...$attributes, 'provider_id' => $provider->id]);
        $run->forceFill(['created_at' => $attributes['created_at']])->save();

        return $run;
    }

    public function test_consolidate_rolls_up_and_deletes_old_runs(): void
    {
        $provider = AiProvider::factory()->create();

        // Old finished runs (to be consolidated). Both pinned to the SAME
        // UTC day (day -5 at 01:00 and 03:00) so both roll into one daily
        // row deterministically.
        $sameDay = Carbon::now('UTC')->startOfDay()->subDays(5);
        $oldA = $this->makeRun($provider, [
            'agent' => 'test.agent',
            'status' => 'ok',
            'duration_ms' => 10,
            'input_tokens' => 1_000_000,
            'output_tokens' => 500_000,
            'cached_tokens' => 0,
            'estimated_cost' => '6.000000',
            'created_at' => $sameDay->copy()->setTime(1, 0),
        ]);
        $oldB = $this->makeRun($provider, [
            'agent' => 'test.agent',
            'status' => 'ok',
            'duration_ms' => 10,
            'input_tokens' => 2_000_000,
            'output_tokens' => 0,
            'cached_tokens' => 1_000_000,
            'estimated_cost' => '3.000000',
            'created_at' => $sameDay->copy()->setTime(3, 0),
        ]);

        // Recent + running runs must survive.
        $recent = $this->makeRun($provider, [
            'agent' => 'test.agent',
            'status' => 'ok',
            'duration_ms' => 10,
            'input_tokens' => 1000,
            'output_tokens' => 100,
            'estimated_cost' => '0.001000',
            'created_at' => Carbon::now('UTC')->subDay(),
        ]);
        $running = $this->makeRun($provider, [
            'agent' => 'test.agent',
            'status' => 'running',
            'duration_ms' => 0,
            'created_at' => Carbon::now('UTC')->subDays(10),
        ]);

        $stats = app(CostEstimator::class)->consolidate(days: 3);

        $this->assertSame(2, $stats['runs_consolidated']);
        $this->assertSame(1, $stats['periods']);
        $this->assertNull($oldA->fresh());
        $this->assertNull($oldB->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertNotNull($running->fresh());

        $daily = AiUsageDaily::query()
            ->where('period', $oldA->created_at->toDateString())
            ->firstOrFail();

        $this->assertSame(2, $daily->runs);
        $this->assertSame(3_000_000, $daily->input_tokens);
        $this->assertSame(500_000, $daily->output_tokens);
        $this->assertSame(1_000_000, $daily->cached_tokens);
        $this->assertSame('9.000000', $daily->estimated_cost);
    }

    public function test_consolidation_counts_runs_with_unknown_usage_and_cost(): void
    {
        $provider = AiProvider::factory()->create();
        $this->makeRun($provider, [
            'agent' => 'image_generation.generate',
            'status' => 'ok',
            'duration_ms' => 10,
            'input_tokens' => null,
            'output_tokens' => null,
            'estimated_cost' => null,
            'created_at' => Carbon::now('UTC')->subDays(5),
        ]);

        app(CostEstimator::class)->consolidate(2);

        $daily = AiUsageDaily::query()->sole();
        $this->assertSame(1, $daily->unknown_token_runs);
        $this->assertSame(1, $daily->unknown_cost_runs);
    }

    public function test_consolidate_is_idempotent_healing_partials(): void
    {
        $provider = AiProvider::factory()->create();

        $this->makeRun($provider, [
            'agent' => 'test.agent',
            'status' => 'ok',
            'duration_ms' => 10,
            'input_tokens' => 1_000_000,
            'output_tokens' => 0,
            'estimated_cost' => '2.000000',
            'created_at' => Carbon::now('UTC')->subDays(2),
        ]);

        app(CostEstimator::class)->consolidate(days: 1);

        // Corrupt the daily row the way a half-finished attempt would
        // (double-written totals): a re-run recomputes from sources, so
        // totals heal instead of accumulating.
        AiUsageDaily::query()
            ->where('ai_provider_id', $provider->id)
            ->update(['runs' => 7, 'input_tokens' => 99, 'estimated_cost' => '99.000000']);

        // Re-create an old run and consolidate again.
        $this->makeRun($provider, [
            'agent' => 'test.agent',
            'status' => 'ok',
            'duration_ms' => 10,
            'input_tokens' => 500_000,
            'output_tokens' => 0,
            'estimated_cost' => '1.000000',
            'created_at' => Carbon::now('UTC')->subDays(2),
        ]);

        app(CostEstimator::class)->consolidate(days: 1);

        $daily = AiUsageDaily::query()
            ->where('ai_provider_id', $provider->id)
            ->firstOrFail();

        $this->assertSame(1, $daily->runs);
        $this->assertSame(500_000, $daily->input_tokens);
        $this->assertSame('1.000000', $daily->estimated_cost);
    }

    public function test_total_spend_spans_live_and_consolidated_rows(): void
    {
        $provider = AiProvider::factory()->create();

        $this->makeRun($provider, [
            'agent' => 'test.agent',
            'status' => 'ok',
            'duration_ms' => 10,
            'input_tokens' => 1000,
            'estimated_cost' => '0.250000',
            'created_at' => Carbon::now('UTC')->subDay(),
        ]);

        AiUsageDaily::create([
            'period' => Carbon::now('UTC')->subDays(40)->toDateString(),
            'ai_provider_id' => $provider->id,
            'runs' => 10,
            'estimated_cost' => '3.500000',
        ]);

        $spend = app(CostEstimator::class)->totalSpend();

        $this->assertSame(3.75, $spend);
    }

    public function test_spend_by_day_merges_both_sources_sorted(): void
    {
        $provider = AiProvider::factory()->create();

        $this->makeRun($provider, [
            'agent' => 'test.agent',
            'status' => 'ok',
            'duration_ms' => 10,
            'estimated_cost' => '0.100000',
            'created_at' => Carbon::now('UTC')->subDays(2),
        ]);
        AiUsageDaily::create([
            'period' => Carbon::now('UTC')->subDays(2)->toDateString(),
            'ai_provider_id' => $provider->id,
            'estimated_cost' => '1.000000',
        ]);
        AiUsageDaily::create([
            'period' => Carbon::now('UTC')->subDays(10)->toDateString(),
            'ai_provider_id' => $provider->id,
            'estimated_cost' => '2.000000',
        ]);

        $series = app(CostEstimator::class)->spendByDay();
        $keys = array_keys($series);

        $this->assertSame(2, count($series));
        $this->assertSame(1.1, $series[$keys[1]]);
        $this->assertSame(2.0, $series[$keys[0]]);
    }
}
