<?php

declare(strict_types=1);

namespace Prism\Bedrock\Schemas\Titan;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Bedrock\Contracts\BedrockImagesHandler;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response as ImagesResponse;
use Prism\Prism\Images\ResponseBuilder;
use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class TitanImagesHandler extends BedrockImagesHandler
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

        return $this->buildResponse();
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildPayload(Request $request): array
    {
        return array_filter([
            'taskType' => $request->providerOptions('taskType') ?? 'TEXT_IMAGE',
            'textToImageParams' => array_filter([
                'text' => $request->prompt(),
                'negativeText' => $request->providerOptions('negativeText'),
            ]),
            'imageGenerationConfig' => array_filter([
                ...Arr::only($request->providerOptions(), [
                    'numberOfImages',
                    'quality',
                    'cfgScale',
                    'height',
                    'width',
                    'seed',
                ]),
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }

    protected function buildResponse(): ImagesResponse
    {
        $data = $this->httpResponse->json();

        $error = data_get($data, 'error');

        if ($error !== null) {
            throw new PrismException("Titan: Image generation failed - {$error}");
        }

        $images = [];

        foreach (data_get($data, 'images', []) as $base64) {
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
}
