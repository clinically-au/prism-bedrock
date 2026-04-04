<?php

declare(strict_types=1);

namespace Clinically\PrismBedrock\Schemas\Titan;

use Clinically\PrismBedrock\Contracts\BedrockEmbeddingsHandler;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Embeddings\Request;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Throwable;

class TitanEmbeddingsHandler extends BedrockEmbeddingsHandler
{
    /**
     * @var array<int, Response>
     */
    protected array $httpResponses = [];

    #[\Override]
    public function handle(Request $request): EmbeddingsResponse
    {
        try {
            foreach ($request->inputs() as $input) {
                $this->httpResponses[] = $this->client->post(
                    'invoke',
                    static::buildPayload($input, $request->providerOptions())
                );
            }
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }

        return $this->buildResponse();
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     * @return array<string, mixed>
     */
    public static function buildPayload(string $input, array $providerOptions = []): array
    {
        return array_filter([
            'inputText' => $input,
            ...Arr::only($providerOptions, [
                'dimensions',
                'normalize',
                'embeddingTypes',
            ]),
        ], fn (mixed $value): bool => $value !== null);
    }

    protected function buildResponse(): EmbeddingsResponse
    {
        return new EmbeddingsResponse(
            embeddings: array_map(function (Response $response): Embedding {
                return Embedding::fromArray(
                    data_get($response->json(), 'embedding')
                        ?? data_get($response->json(), 'embeddingsByType.float', [])
                );
            }, $this->httpResponses),
            usage: new EmbeddingsUsage(
                tokens: array_sum(array_map(
                    fn (Response $response): int => (int) data_get($response->json(), 'inputTextTokenCount', 0),
                    $this->httpResponses,
                )),
            ),
            meta: new Meta(id: '', model: ''),
            raw: array_map(fn (Response $response): mixed => $response->json(), $this->httpResponses),
        );
    }
}
