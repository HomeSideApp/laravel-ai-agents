<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Tests\Unit\Fixtures;

use HomeSide\AiAgents\Contracts\ProposalHandler;
use HomeSide\AiAgents\Models\AiActionProposal;

/**
 * Fake ProposalHandler for tests.
 *
 * Configurable type, rules, execute result, and exception throwing.
 * Tracks the number of times execute() is called.
 */
final class FakeProposalHandler implements ProposalHandler
{
    public string $typeValue;

    /**
     * @var array<string, mixed>
     */
    public array $rulesValue;

    /**
     * @var array<string, mixed>
     */
    public array $executeResult;

    /**
     * Whether execute() should throw an exception.
     */
    public bool $shouldThrow = false;

    /**
     * The exception message when shouldThrow is true.
     */
    public string $throwMessage = 'Handler execution error';

    /**
     * Public counter of execute() calls.
     */
    public int $executeCount = 0;

    /**
     * @param  string  $type  The proposal type for this handler.
     * @param  array<string, mixed>  $rules  Validation rules for the payload.
     * @param  array<string, mixed>  $result  Return value of execute().
     */
    public function __construct(
        string $type = 'test_action',
        array $rules = [],
        array $result = ['status' => 'done'],
    ) {
        $this->typeValue = $type;
        $this->rulesValue = $rules;
        $this->executeResult = $result;
    }

    public function type(): string
    {
        return $this->typeValue;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->rulesValue;
    }

    public function execute(AiActionProposal $proposal): array
    {
        $this->executeCount++;

        if ($this->shouldThrow) {
            throw new \RuntimeException($this->throwMessage);
        }

        return $this->executeResult;
    }
}
