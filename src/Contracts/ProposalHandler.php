<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Contracts;

use HomeSide\AiAgents\Models\AiActionProposal;

/**
 * Contract for handling a specific proposal type.
 *
 * Each implementation maps to a single proposal `type` and provides:
 * - validation rules for the payload (`rules()`),
 * - the actual execution logic (`execute()`).
 *
 * Types must match `^[a-z0-9_]+$` (lowercase slug-like, no dots).
 */
interface ProposalHandler
{
    /**
     * The proposal type this handler processes.
     *
     * Must match `^[a-z0-9_]+$`.
     */
    public function type(): string;

    /**
     * Validation rules for the proposal payload.
     *
     * Rules follow the standard Laravel validation format.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Execute the proposed action.
     *
     * Called by the execution job after the proposal has been accepted.
     * Must return a serialisable array representing the execution result.
     *
     * @param  AiActionProposal  $proposal  The accepted proposal to execute.
     * @return array<string, mixed> The serialisable execution result.
     */
    public function execute(AiActionProposal $proposal): array;
}
