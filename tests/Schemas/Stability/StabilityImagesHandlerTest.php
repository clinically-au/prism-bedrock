<?php

declare(strict_types=1);

namespace Tests\Schemas\Stability;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Bedrock\Bedrock;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

it('can generate an image with core model', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'stability/generate-image-core');

    $response = Prism::image()
        ->using(Bedrock::KEY, 'stability.stable-image-core-v1:0')
        ->withPrompt('A cute robot')
        ->generate();

    expect($response->hasImages())->toBeTrue();
    expect($response->imageCount())->toBe(1);
    expect($response->firstImage()->base64())->not->toBeEmpty();
});

it('passes provider options to core model payload', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'stability/generate-image-core');

    Prism::image()
        ->using(Bedrock::KEY, 'stability.stable-image-core-v1:0')
        ->withProviderOptions([
            'aspect_ratio' => '16:9',
            'negative_prompt' => 'blurry',
            'seed' => 42,
        ])
        ->withPrompt('A cute robot')
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        expect($data['prompt'])->toBe('A cute robot');
        expect($data['aspect_ratio'])->toBe('16:9');
        expect($data['negative_prompt'])->toBe('blurry');
        expect($data['seed'])->toBe(42);

        return true;
    });
});

it('can generate an image with sdxl model', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'stability/generate-image-sdxl');

    $response = Prism::image()
        ->using(Bedrock::KEY, 'stability.stable-diffusion-xl-v1')
        ->withPrompt('A steampunk robot')
        ->generate();

    expect($response->hasImages())->toBeTrue();
    expect($response->imageCount())->toBe(1);
    expect($response->firstImage()->base64())->not->toBeEmpty();
});

it('builds correct sdxl payload with negative prompt', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'stability/generate-image-sdxl');

    Prism::image()
        ->using(Bedrock::KEY, 'stability.stable-diffusion-xl-v1')
        ->withProviderOptions([
            'negative_prompt' => 'low quality',
            'cfg_scale' => 10,
            'steps' => 30,
            'style_preset' => 'cinematic',
        ])
        ->withPrompt('A steampunk robot')
        ->generate();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        expect($data['text_prompts'])->toBe([
            ['text' => 'A steampunk robot'],
            ['text' => 'low quality', 'weight' => -1.0],
        ]);
        expect($data['cfg_scale'])->toBe(10);
        expect($data['steps'])->toBe(30);
        expect($data['style_preset'])->toBe('cinematic');

        return true;
    });
});
