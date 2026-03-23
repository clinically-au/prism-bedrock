<?php

namespace Clinically\PrismBedrock\Enums;

use Illuminate\Support\Str;
use Clinically\PrismBedrock\Contracts\BedrockEmbeddingsHandler;
use Clinically\PrismBedrock\Contracts\BedrockImagesHandler;
use Clinically\PrismBedrock\Contracts\BedrockStreamHandler;
use Clinically\PrismBedrock\Contracts\BedrockStructuredHandler;
use Clinically\PrismBedrock\Contracts\BedrockTextHandler;
use Clinically\PrismBedrock\Schemas\Anthropic\AnthropicStreamHandler;
use Clinically\PrismBedrock\Schemas\Anthropic\AnthropicStructuredHandler;
use Clinically\PrismBedrock\Schemas\Anthropic\AnthropicTextHandler;
use Clinically\PrismBedrock\Schemas\Cohere\CohereEmbeddingsHandler;
use Clinically\PrismBedrock\Schemas\Converse\ConverseStreamHandler;
use Clinically\PrismBedrock\Schemas\Converse\ConverseStructuredHandler;
use Clinically\PrismBedrock\Schemas\Converse\ConverseTextHandler;
use Clinically\PrismBedrock\Schemas\Stability\StabilityImagesHandler;
use Clinically\PrismBedrock\Schemas\Titan\TitanImagesHandler;

enum BedrockSchema: string
{
    case Converse = 'converse';
    case Anthropic = 'anthropic';
    case Cohere = 'cohere';
    case Stability = 'stability';
    case Titan = 'titan';

    /**
     * @return null|class-string<BedrockTextHandler>
     */
    public function textHandler(): ?string
    {
        return match ($this) {
            self::Anthropic => AnthropicTextHandler::class,
            self::Converse => ConverseTextHandler::class,
            default => null
        };
    }

    /**
     * @return null|class-string<BedrockStructuredHandler>
     */
    public function structuredHandler(): ?string
    {
        return match ($this) {
            self::Anthropic => AnthropicStructuredHandler::class,
            self::Converse => ConverseStructuredHandler::class,
            default => null
        };
    }

    /**
     * @return null|class-string<BedrockStreamHandler>
     */
    public function streamHandler(): ?string
    {
        return match ($this) {
            self::Anthropic => AnthropicStreamHandler::class,
            self::Converse => ConverseStreamHandler::class,
            default => null
        };
    }

    /**
     * @return null|class-string<BedrockEmbeddingsHandler>
     */
    public function embeddingsHandler(): ?string
    {
        return match ($this) {
            self::Cohere => CohereEmbeddingsHandler::class,
            default => null
        };
    }

    /**
     * @return null|class-string<BedrockImagesHandler>
     */
    public function imagesHandler(): ?string
    {
        return match ($this) {
            self::Stability => StabilityImagesHandler::class,
            self::Titan => TitanImagesHandler::class,
            default => null,
        };
    }

    public function defaultApiVersion(): ?string
    {
        return match ($this) {
            self::Anthropic => 'bedrock-2023-05-31',
            default => null
        };
    }

    public static function fromModelString(string $string): self
    {
        if (Str::contains($string, 'anthropic.')) {
            return self::Anthropic;
        }

        if (Str::contains($string, 'cohere.')) {
            return self::Cohere;
        }

        if (Str::contains($string, 'stability.')) {
            return self::Stability;
        }

        if (Str::contains($string, 'amazon.titan-image')) {
            return self::Titan;
        }

        return self::Converse;
    }
}
