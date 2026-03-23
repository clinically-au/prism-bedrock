<?php

declare(strict_types=1);

namespace Prism\Bedrock\Concerns;

use Aws\Api\Parser\NonSeekableStreamDecodingEventStreamIterator;
use Generator;
use GuzzleHttp\Psr7\NoSeekStream;
use Illuminate\Http\Client\Response;
use Prism\Prism\Exceptions\PrismException;

trait ParsesEventStream
{
    /**
     * Iterate over an AWS binary event stream response, yielding decoded events.
     *
     * @return Generator<array{type: string, data: array<string, mixed>}>
     */
    protected function iterateEventStream(Response $response): Generator
    {
        $body = $response->toPsrResponse()->getBody();
        $nonSeekableStream = new NoSeekStream($body);

        $iterator = new NonSeekableStreamDecodingEventStreamIterator($nonSeekableStream);

        foreach ($iterator as $event) {
            $headers = $event['headers'] ?? [];
            $payload = $event['payload'] ?? null;

            $messageType = $headers[':message-type'] ?? 'event';

            if ($messageType === 'error' || $messageType === 'exception') {
                $errorData = $this->decodePayload($payload);
                $errorCode = $headers[':error-code'] ?? $headers[':exception-type'] ?? 'unknown';
                $errorMessage = $errorData['message'] ?? $errorData['Message'] ?? 'Unknown error';

                throw new PrismException(
                    "Bedrock event stream error: {$errorCode} - {$errorMessage}"
                );
            }

            if ($messageType !== 'event') {
                continue;
            }

            $eventType = $headers[':event-type'] ?? 'unknown';
            $data = $this->decodePayload($payload);

            yield [
                'type' => $eventType,
                'data' => $data,
            ];
        }
    }

    /**
     * Decode the payload from an event stream frame.
     *
     * @return array<string, mixed>
     */
    protected function decodePayload(mixed $payload): array
    {
        if ($payload === null) {
            return [];
        }

        $contents = (string) $payload;

        if ($contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
