<?php

declare(strict_types=1);

namespace Prism\Bedrock\Schemas\Stability;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Prism\Bedrock\Contracts\BedrockImagesHandler;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class StabilityImagesHandler extends BedrockImagesHandler
{
    protected Response $httpResponse;

    #[\Override]
    public function handle(Request $request): ImagesResponse
    {
        try {
            $this->httpResponse = $this->client->post(
                'invoke',
                static::buildPayload($request)
            );
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }

        return $this->buildResponse($request);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildPayload(Request $request): array
    {
        if (static::isSDXL($request->model())) {
            return static::buildSDXLPayload($request);
        }

        return static::buildCorePayload($request);
    }

    /**
     * Build payload for Stability Core, Ultra, and SD3 models.
     *
     * @return array<string, mixed>
     */
    protected static function buildCorePayload(Request $request): array
    {
        return array_filter([
            'prompt' => $request->prompt(),
            ...Arr::only($request->providerOptions(), [
                'aspect_ratio',
                'negative_prompt',
                'output_format',
                'seed',
            ]),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Build payload for Stability SDXL models.
     *
     * @return array<string, mixed>
     */
    protected static function buildSDXLPayload(Request $request): array
    {
        $textPrompts = [['text' => $request->prompt()]];

        $negativePrompt = $request->providerOptions('negative_prompt');

        if ($negativePrompt !== null) {
            $textPrompts[] = ['text' => $negativePrompt, 'weight' => -1.0];
        }

        return array_filter([
            'text_prompts' => $textPrompts,
            ...Arr::only($request->providerOptions(), [
                'cfg_scale',
                'seed',
                'steps',
                'style_preset',
                'height',
                'width',
                'samples',
            ]),
        ], fn (mixed $value): bool => $value !== null);
    }

    protected function buildResponse(Request $request): ImagesResponse
    {
        $data = $this->httpResponse->json();

        if (static::isSDXL($request->model())) {
            return $this->buildSDXLResponse($data);
        }

        return $this->buildCoreResponse($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function buildCoreResponse(array $data): ImagesResponse
    {
        $images = [];

        foreach (data_get($data, 'images', []) as $index => $base64) {
            $finishReason = data_get($data, "finish_reasons.$index");

            if ($finishReason !== null) {
                throw new PrismException("Stability: Image generation failed - {$finishReason}");
            }

            $images[] = new GeneratedImage(
                base64: $base64,
                mimeType: 'image/png',
            );
        }

        return (new ResponseBuilder(
            usage: new Usage(
                promptTokens: 0,
                completionTokens: 0,
            ),
            meta: new Meta(id: '', model: ''),
            images: $images,
            raw: $data,
        ))->toResponse();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function buildSDXLResponse(array $data): ImagesResponse
    {
        $images = [];

        foreach (data_get($data, 'artifacts', []) as $artifact) {
            $finishReason = data_get($artifact, 'finishReason');

            if ($finishReason !== 'SUCCESS') {
                throw new PrismException("Stability: Image generation failed - {$finishReason}");
            }

            $images[] = new GeneratedImage(
                base64: data_get($artifact, 'base64'),
                mimeType: 'image/png',
            );
        }

        return (new ResponseBuilder(
            usage: new Usage(
                promptTokens: 0,
                completionTokens: 0,
            ),
            meta: new Meta(id: '', model: ''),
            images: $images,
            raw: $data,
        ))->toResponse();
    }

    protected static function isSDXL(string $model): bool
    {
        return Str::contains($model, 'stable-diffusion-xl');
    }
}
