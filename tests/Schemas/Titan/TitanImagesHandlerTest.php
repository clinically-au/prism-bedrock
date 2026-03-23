<?php

declare(strict_types=1);

namespace Tests\Schemas\Titan;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Bedrock\Bedrock;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

it('can generate an image', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'titan/generate-image');

    $response = Prism::image()
        ->using(Bedrock::KEY, 'amazon.titan-image-generator-v2:0')
        ->withPrompt('A beautiful sunset')
        ->generate();

    expect($response->hasImages())->toBeTrue();
    expect($response->imageCount())->toBe(1);
    expect($response->firstImage()->base64())->not->toBeEmpty();
});

it('passes provider options to payload', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'titan/generate-image');

    Prism::image()
        ->using(Bedrock::KEY, 'amazon.titan-image-generator-v2:0')
        ->withProviderOptions([
            'negativeText' => 'blurry',
            'numberOfImages' => 2,
            'quality' => 'premium',
            'height' => 1024,
            'width' => 1024,
            'seed' => 42,
        ])
        ->withPrompt('A beautiful sunset')
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        expect($data['taskType'])->toBe('TEXT_IMAGE');
        expect($data['textToImageParams']['text'])->toBe('A beautiful sunset');
        expect($data['textToImageParams']['negativeText'])->toBe('blurry');
        expect($data['imageGenerationConfig']['numberOfImages'])->toBe(2);
        expect($data['imageGenerationConfig']['quality'])->toBe('premium');
        expect($data['imageGenerationConfig']['height'])->toBe(1024);
        expect($data['imageGenerationConfig']['width'])->toBe(1024);
        expect($data['imageGenerationConfig']['seed'])->toBe(42);

        return true;
    });
});
