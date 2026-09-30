<?php

declare(strict_types=1);

namespace HomeSide\AiAgents\Enums;

/**
 * Tool-calling wire formats a model may expect.
 *
 * Most OpenAI-protocol models use native function calling; some community
 * models (certain qwen builds) parse XML-structured tool calls instead.
 * Declaring the format per provider lets the SDK-side mapping and the
 * agent-side prompts agree on one format.
 */
enum ToolCallFormat: string
{
    /** Native OpenAI function calling (default). */
    case Native = 'native';

    /** XML-structured tool calls (e.g. some qwen builds). */
    case Xml = 'xml';
}
