<?php

namespace App\Http\Controllers;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\McpResource;

/**
 * Example MCP Tools and Resources
 *
 * This file demonstrates how to create MCP tools and resources using attributes.
 * After configuring the template, replace this with your own implementation.
 *
 * MCP Tools: Methods marked with #[McpTool] are automatically discovered and
 * exposed to MCP clients like Claude.
 *
 * MCP Resources: Methods marked with #[McpResource] provide structured data
 * that clients can fetch.
 */
class CalculatorElements
{
    /**
     * Adds two numbers together.
     *
     * @param int $a The first number
     * @param int $b The second number
     * @return int The sum of the two numbers
     */
    #[McpTool]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /**
     * Performs basic arithmetic operations.
     */
    #[McpTool(name: 'calculate')]
    public function calculate(float $a, float $b, string $operation): float|string
    {
        return match($operation) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            'multiply' => $a * $b,
            'divide' => $b != 0 ? $a / $b : 'Error: Division by zero',
            default => 'Error: Unknown operation'
        };
    }

    #[McpResource(
        uri: 'config://calculator/settings',
        name: 'calculator_config',
        mimeType: 'application/json'
    )]
    public function getSettings(): array
    {
        return ['precision' => 2, 'allow_negative' => true];
    }
}