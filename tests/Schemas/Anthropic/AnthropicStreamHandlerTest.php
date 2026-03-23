<?php

declare(strict_types=1);

namespace Tests\Schemas\Anthropic;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Bedrock\Bedrock;
use Prism\Bedrock\Schemas\Anthropic\AnthropicStreamHandler;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Testable subclass that bypasses the binary event stream + base64 decoding.
 *
 * The real handler decodes binary event stream frames, then base64-decodes
 * the inner Anthropic event. This subclass skips both layers and yields
 * pre-decoded Anthropic events directly.
 */
class TestableAnthropicStreamHandler extends AnthropicStreamHandler
{
    /** @var array<array<string, mixed>> */
    protected array $fakeAnthropicEvents = [];

    /**
     * @param  array<array<string, mixed>>  $events  Pre-decoded Anthropic events (e.g. ['type' => 'message_start', ...])
     */
    public function withAnthropicEvents(array $events): self
    {
        $this->fakeAnthropicEvents = $events;

        return $this;
    }

    protected function sendRequest(Request $request): Response
    {
        return new Response(new \GuzzleHttp\Psr7\Response(200));
    }

    /**
     * Override iterateEventStream to yield fake events that will be decoded
     * by decodeAnthropicEvent. We wrap each event as base64-encoded JSON
     * inside a `bytes` key, matching the real Bedrock format.
     */
    protected function iterateEventStream(Response $response): Generator
    {
        foreach ($this->fakeAnthropicEvents as $event) {
            yield [
                'type' => 'chunk',
                'data' => [
                    'bytes' => base64_encode(json_encode($event)),
                ],
            ];
        }
    }
}

function createAnthropicStreamHandler(array $events): TestableAnthropicStreamHandler
{
    $provider = \Mockery::mock(Bedrock::class);
    $client = \Mockery::mock(PendingRequest::class);

    $handler = new TestableAnthropicStreamHandler($provider, $client);
    $handler->withAnthropicEvents($events);

    return $handler;
}

function createAnthropicTextRequest(string $prompt = 'Hello'): Request
{
    return new Request(
        model: 'anthropic.claude-3-5-haiku-20241022-v1:0',
        providerKey: 'bedrock',
        systemPrompts: [],
        prompt: $prompt,
        messages: [new UserMessage($prompt)],
        maxSteps: 1,
        maxTokens: 1024,
        temperature: null,
        topP: null,
        tools: [],
        clientOptions: [],
        clientRetry: [0],
        toolChoice: null,
    );
}

function collectAnthropicEvents(Generator $generator): array
{
    $events = [];

    foreach ($generator as $event) {
        $events[] = $event;
    }

    return $events;
}

it('streams basic text', function (): void {
    $handler = createAnthropicStreamHandler([
        ['type' => 'message_start', 'message' => [
            'id' => 'msg_123',
            'model' => 'claude-3-5-haiku-20241022',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ' world']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]],
        ['type' => 'message_stop'],
    ]);

    $events = collectAnthropicEvents($handler->handle(createAnthropicTextRequest()));

    expect($events[0])->toBeInstanceOf(StreamStartEvent::class);
    expect($events[0]->model)->toBe('claude-3-5-haiku-20241022');
    expect($events[1])->toBeInstanceOf(StepStartEvent::class);
    expect($events[2])->toBeInstanceOf(TextStartEvent::class);
    expect($events[3])->toBeInstanceOf(TextDeltaEvent::class);
    expect($events[3]->delta)->toBe('Hello');
    expect($events[4])->toBeInstanceOf(TextDeltaEvent::class);
    expect($events[4]->delta)->toBe(' world');
    expect($events[5])->toBeInstanceOf(TextCompleteEvent::class);
    expect($events[6])->toBeInstanceOf(StepFinishEvent::class);
    expect($events[7])->toBeInstanceOf(StreamEndEvent::class);
    expect($events[7]->finishReason)->toBe(FinishReason::Stop);
    expect($events[7]->usage->promptTokens)->toBe(10);
    expect($events[7]->usage->completionTokens)->toBe(5);
});

it('streams tool calls', function (): void {
    $handler = createAnthropicStreamHandler([
        ['type' => 'message_start', 'message' => [
            'id' => 'msg_456',
            'model' => 'claude-3-5-haiku-20241022',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 0],
        ]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => [
            'type' => 'tool_use',
            'id' => 'toolu_1',
            'name' => 'weather',
        ]],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => [
            'type' => 'input_json_delta',
            'partial_json' => '{"city":',
        ]],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => [
            'type' => 'input_json_delta',
            'partial_json' => '"Detroit"}',
        ]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 15]],
        ['type' => 'message_stop'],
    ]);

    $events = collectAnthropicEvents($handler->handle(createAnthropicTextRequest()));

    expect($events[0])->toBeInstanceOf(StreamStartEvent::class);
    expect($events[1])->toBeInstanceOf(StepStartEvent::class);

    $toolDeltas = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallDeltaEvent));
    expect($toolDeltas)->toHaveCount(2);
    expect($toolDeltas[0]->toolName)->toBe('weather');
    expect($toolDeltas[0]->delta)->toBe('{"city":');

    $toolCalls = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));
    expect($toolCalls)->toHaveCount(1);
    expect($toolCalls[0]->toolCall->name)->toBe('weather');
    expect($toolCalls[0]->toolCall->arguments())->toBe(['city' => 'Detroit']);
});

it('tracks cache tokens from message_start', function (): void {
    $handler = createAnthropicStreamHandler([
        ['type' => 'message_start', 'message' => [
            'id' => 'msg_789',
            'model' => 'claude-3-5-haiku-20241022',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 0,
                'cache_creation_input_tokens' => 200,
                'cache_read_input_tokens' => 100,
            ],
        ]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]],
        ['type' => 'message_stop'],
    ]);

    $events = collectAnthropicEvents($handler->handle(createAnthropicTextRequest()));

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEndEvent));
    expect($streamEnd[0]->usage->cacheWriteInputTokens)->toBe(200);
    expect($streamEnd[0]->usage->cacheReadInputTokens)->toBe(100);
    expect($streamEnd[0]->usage->completionTokens)->toBe(3);
});

it('handles empty text deltas gracefully', function (): void {
    $handler = createAnthropicStreamHandler([
        ['type' => 'message_start', 'message' => [
            'id' => 'msg_empty',
            'model' => 'claude-3-5-haiku-20241022',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
        ]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]],
        ['type' => 'message_stop'],
    ]);

    $events = collectAnthropicEvents($handler->handle(createAnthropicTextRequest()));

    $textDeltas = array_values(array_filter($events, fn ($e) => $e instanceof TextDeltaEvent));
    expect($textDeltas)->toHaveCount(1);
    expect($textDeltas[0]->delta)->toBe('Hello');
});

it('ignores ping events', function (): void {
    $handler = createAnthropicStreamHandler([
        ['type' => 'ping'],
        ['type' => 'message_start', 'message' => [
            'id' => 'msg_ping',
            'model' => 'claude-3-5-haiku-20241022',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
        ]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]],
        ['type' => 'message_stop'],
    ]);

    $events = collectAnthropicEvents($handler->handle(createAnthropicTextRequest()));

    expect($events[0])->toBeInstanceOf(StreamStartEvent::class);
});
