<?php

declare(strict_types=1);

namespace Tests\Schemas\Titan;

use Clinically\PrismBedrock\Bedrock;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

it('can generate titan embeddings from an input', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'titan/generate-embeddings-from-input');

    $response = Prism::embeddings()
        ->using(Bedrock::KEY, 'amazon.titan-embed-text-v2:0')
        ->fromInput('Hello, world!')
        ->asEmbeddings();

    expect($response->embeddings)->toHaveCount(1);
    expect($response->embeddings[0]->embedding)->toHaveCount(10);
    expect($response->usage->tokens)->toBe(4);
});

it('can generate titan embeddings from multiple inputs', function (): void {
    FixtureResponse::fakeResponseSequence('*', 'titan/generate-embeddings-from-input');

    $response = Prism::embeddings()
        ->using(Bedrock::KEY, 'amazon.titan-embed-text-v2:0')
        ->fromInput('The food was delicious.')
        ->fromInput('The drinks were not so good.')
        ->asEmbeddings();

    Http::assertSentCount(2);

    expect($response->embeddings)->toHaveCount(2);
    expect($response->usage->tokens)->toBe(8);
});

it('passes titan provider options to the payload', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'titan/generate-embeddings-from-input');

    Prism::embeddings()
        ->using(Bedrock::KEY, 'amazon.titan-embed-text-v2:0')
        ->withProviderOptions([
            'dimensions' => 256,
            'normalize' => false,
            'embeddingTypes' => ['float'],
            'ignoredOption' => 'filtered out',
        ])
        ->fromInput('Hello, world!')
        ->asEmbeddings();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        expect($data['inputText'])->toBe('Hello, world!');
        expect($data['dimensions'])->toBe(256);
        expect($data['normalize'])->toBeFalse();
        expect($data['embeddingTypes'])->toBe(['float']);
        expect($data)->not->toHaveKey('ignoredOption');

        return true;
    });
});
