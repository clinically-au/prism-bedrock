<?php

declare(strict_types=1);

namespace Clinically\PrismBedrock\Schemas\Anthropic\Maps;

use InvalidArgumentException;
use Prism\Prism\Enums\ToolChoice;

class ToolChoiceMap
{
    /**
     * @return array<string, mixed>|null
     */
    public static function map(string|ToolChoice|null $toolChoice): ?array
    {
        if (is_null($toolChoice)) {
            return null;
        }

        if (is_string($toolChoice)) {
            return [
                'type' => 'tool',
                'name' => $toolChoice,
            ];
        }

        if (! in_array($toolChoice, [ToolChoice::Auto, ToolChoice::Any])) {
            throw new InvalidArgumentException('Invalid tool choice');
        }

        return match ($toolChoice) {
            ToolChoice::Auto => ['type' => 'auto'],
            ToolChoice::Any => ['type' => 'any'],
        };

    }
}
