<?php

namespace Clinically\PrismBedrock\Enums;

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
use Clinically\PrismBedrock\Schemas\Titan\TitanEmbeddingsHandler;
use Clinically\PrismBedrock\Schemas\Titan\TitanImagesHandler;
use Illuminate\Support\Str;

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
            self::Titan => TitanEmbeddingsHandler::class,
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
        if (self::isAnthropicModel($string)) {
            return self::Anthropic;
        }

        if (self::isCohereModel($string)) {
            return self::Cohere;
        }

        if (self::isStabilityModel($string)) {
            return self::Stability;
        }

        if (self::isTitanModel($string)) {
            return self::Titan;
        }

        return self::Converse;
    }

    public static function isAnthropicModel(string $string): bool
    {
        return Str::contains($string, 'anthropic.');
    }

    public static function isCohereModel(string $string): bool
    {
        return Str::contains($string, 'cohere.');
    }

    public static function isStabilityModel(string $string): bool
    {
        return Str::contains($string, 'stability.');
    }

    public static function isTitanModel(string $string): bool
    {
        return Str::contains($string, 'amazon.titan-image')
            || Str::contains($string, 'amazon.titan-embed');
    }
}
