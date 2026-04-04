<?php

declare(strict_types=1);

namespace Tests;

use Clinically\PrismBedrock\Enums\BedrockSchema;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

it('url-encodes model identifiers in the request path', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'converse/generate-text-with-a-prompt');

    $model = 'arn:aws:bedrock:us-east-1:999999999999:inference-profile/us.amazon.nova-pro-v1:0';

    Prism::text()
        ->using('bedrock', $model)
        ->withPrompt('Explain quantum computing in simple terms')
        ->asText();

    Http::assertSent(function (HttpRequest $request) use ($model): bool {
        return str_contains($request->url(), rawurlencode($model));
    });
});

it('routes anthropic document requests through converse by default', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'converse/query-a-pdf-document');

    Prism::text()
        ->using('bedrock', 'anthropic.claude-3-7-sonnet-20250219-v1:0')
        ->withMessages([
            new UserMessage('Analyze this document', [
                Document::fromLocalPath('tests/Fixtures/document.pdf'),
            ]),
        ])
        ->asText();

    Http::assertSent(function (HttpRequest $request): bool {
        return str_ends_with($request->url(), '/converse');
    });
});

it('honors explicit api schema overrides', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'anthropic/generate-text-with-a-prompt');

    Prism::text()
        ->using('bedrock', 'anthropic.claude-3-7-sonnet-20250219-v1:0')
        ->withProviderOptions(['apiSchema' => BedrockSchema::Anthropic])
        ->withPrompt('Explain quantum computing in simple terms')
        ->asText();

    Http::assertSent(function (HttpRequest $request): bool {
        return str_ends_with($request->url(), '/invoke');
    });
});
