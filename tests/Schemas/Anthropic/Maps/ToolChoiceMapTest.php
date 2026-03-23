<?php

declare(strict_types=1);

namespace Tests\Schemas\Anthropic\Maps;

use InvalidArgumentException;
use Clinically\PrismBedrock\Schemas\Anthropic\Maps\ToolChoiceMap;
use Prism\Prism\Enums\ToolChoice;

it('returns null when tool choice is null', function (): void {
    expect(ToolChoiceMap::map(null))->toBeNull();
});

it('maps a specific tool correctly', function (): void {
    expect(ToolChoiceMap::map('search'))
        ->toBe([
            'type' => 'tool',
            'name' => 'search',
        ]);
});

it('maps auto tool correctly', function (): void {
    expect(ToolChoiceMap::map(ToolChoice::Auto))
        ->toBe(['type' => 'auto']);
});

it('maps any tool correctly', function (): void {
    expect(ToolChoiceMap::map(ToolChoice::Any))
        ->toBe(['type' => 'any']);
});

it('throws exception for invalid tool choice', function (): void {
    ToolChoiceMap::map(ToolChoice::None);
})->throws(InvalidArgumentException::class, 'Invalid tool choice');
