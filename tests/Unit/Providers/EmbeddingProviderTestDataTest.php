<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Providers;

use HomeSide\AiAgents\Enums\EmbeddingDimensionsSource;
use HomeSide\AiAgents\Enums\EmbeddingProviderTestError;
use HomeSide\AiAgents\Providers\EmbeddingProviderTestData;
use HomeSide\AiAgents\Tests\TestCase;
use InvalidArgumentException;

/**
 * EmbeddingProviderTestData invariants: a success always carries dimensions
 * and a source; an error always carries a code and no dimensions.
 */
final class EmbeddingProviderTestDataTest extends TestCase
{
    public function test_configured_success_carries_dimensions_and_source(): void
    {
        $data = EmbeddingProviderTestData::configured(10, 768);

        $this->assertTrue($data->successful());
        $this->assertSame('ok', $data->status);
        $this->assertSame(768, $data->dimensions);
        $this->assertSame(EmbeddingDimensionsSource::Configured, $data->dimensionsSource);
        $this->assertNull($data->error);
    }

    public function test_discovered_success_carries_dimensions_and_source(): void
    {
        $data = EmbeddingProviderTestData::discovered(10, 768);

        $this->assertTrue($data->successful());
        $this->assertSame(768, $data->dimensions);
        $this->assertSame(EmbeddingDimensionsSource::Discovered, $data->dimensionsSource);
        $this->assertNull($data->error);
    }

    public function test_error_carries_code_and_no_dimensions(): void
    {
        $data = EmbeddingProviderTestData::error(EmbeddingProviderTestError::EmptyVector, 'empty');

        $this->assertFalse($data->successful());
        $this->assertSame('error', $data->status);
        $this->assertNull($data->dimensions);
        $this->assertNull($data->dimensionsSource);
        $this->assertSame(EmbeddingProviderTestError::EmptyVector, $data->error);
    }

    public function test_configured_rejects_zero_dimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmbeddingProviderTestData::configured(10, 0);
    }

    public function test_discovered_rejects_negative_dimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmbeddingProviderTestData::discovered(10, -1);
    }

    public function test_configured_rejects_negative_latency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmbeddingProviderTestData::configured(-1, 768);
    }
}
