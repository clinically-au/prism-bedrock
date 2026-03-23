<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Bedrock\Bedrock;
use Prism\Bedrock\Schemas\Converse\ConverseStreamHandler;
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
 * Testable subclass that bypasses the binary event stream layer.
 */
class TestableConverseStreamHandler extends ConverseStreamHandler
{
    /** @var array<array{type: string, data: array<string, mixed>}> */
    protected array $fakeEvents = [];

    /**
     * @param  array<array{type: string, data: array<string, mixed>}>  $events
     */
    public function withEvents(array $events): self
    {
        $this->fakeEvents = $events;

        return $this;
    }

    protected function sendRequest(Request $request): Response
    {
        return new Response(new \GuzzleHttp\Psr7\Response(200));
    }

    protected function iterateEventStream(Response $response): Generator
    {
        foreach ($this->fakeEvents as $event) {
            yield $event;
        }
    }
}

function createConverseStreamHandler(array $events): TestableConverseStreamHandler
{
    $provider = \Mockery::mock(Bedrock::class);
    $client = \Mockery::mock(PendingRequest::class);

    $handler = new TestableConverseStreamHandler($provider, $client);
    $handler->withEvents($events);

    return $handler;
}

function createTextRequest(string $prompt = 'Hello'): Request
{
    return new Request(
        model: 'amazon.nova-micro-v1:0',
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

function collectEvents(Generator $generator): array
{
    $events = [];

    foreach ($generator as $event) {
        $events[] = $event;
    }

    return $events;
}

it('streams basic text', function (): void {
    $handler = createConverseStreamHandler([
        ['type' => 'messageStart', 'data' => ['model' => 'amazon.nova-micro-v1:0']],
        ['type' => 'contentBlockStart', 'data' => ['contentBlockIndex' => 0, 'start' => []]],
        ['type' => 'contentBlockDelta', 'data' => ['delta' => ['text' => 'Hello']]],
        ['type' => 'contentBlockDelta', 'data' => ['delta' => ['text' => ' world']]],
        ['type' => 'contentBlockStop', 'data' => ['contentBlockIndex' => 0]],
        ['type' => 'messageStop', 'data' => ['stopReason' => 'end_turn']],
        ['type' => 'metadata', 'data' => ['usage' => ['inputTokens' => 10, 'outputTokens' => 5]]],
    ]);

    $events = collectEvents($handler->handle(createTextRequest()));

    expect($events[0])->toBeInstanceOf(StreamStartEvent::class);
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
    $handler = createConverseStreamHandler([
        ['type' => 'messageStart', 'data' => ['model' => 'amazon.nova-micro-v1:0']],
        ['type' => 'contentBlockStart', 'data' => [
            'contentBlockIndex' => 0,
            'start' => ['toolUse' => ['toolUseId' => 'tool-1', 'name' => 'weather']],
        ]],
        ['type' => 'contentBlockDelta', 'data' => [
            'delta' => ['toolUse' => ['input' => '{"city":']],
        ]],
        ['type' => 'contentBlockDelta', 'data' => [
            'delta' => ['toolUse' => ['input' => '"Detroit"}']],
        ]],
        ['type' => 'contentBlockStop', 'data' => ['contentBlockIndex' => 0]],
        ['type' => 'messageStop', 'data' => ['stopReason' => 'tool_use']],
        ['type' => 'metadata', 'data' => ['usage' => ['inputTokens' => 20, 'outputTokens' => 10]]],
    ]);

    $events = collectEvents($handler->handle(createTextRequest()));

    expect($events[0])->toBeInstanceOf(StreamStartEvent::class);
    expect($events[1])->toBeInstanceOf(StepStartEvent::class);

    $toolDeltas = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallDeltaEvent));
    expect($toolDeltas)->toHaveCount(2);
    expect($toolDeltas[0]->toolName)->toBe('weather');

    $toolCalls = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));
    expect($toolCalls)->toHaveCount(1);
    expect($toolCalls[0]->toolCall->name)->toBe('weather');
    expect($toolCalls[0]->toolCall->arguments())->toBe(['city' => 'Detroit']);
});

it('handles empty text deltas gracefully', function (): void {
    $handler = createConverseStreamHandler([
        ['type' => 'messageStart', 'data' => ['model' => 'amazon.nova-micro-v1:0']],
        ['type' => 'contentBlockStart', 'data' => ['contentBlockIndex' => 0, 'start' => []]],
        ['type' => 'contentBlockDelta', 'data' => ['delta' => ['text' => '']]],
        ['type' => 'contentBlockDelta', 'data' => ['delta' => ['text' => 'Hello']]],
        ['type' => 'contentBlockStop', 'data' => ['contentBlockIndex' => 0]],
        ['type' => 'messageStop', 'data' => ['stopReason' => 'end_turn']],
        ['type' => 'metadata', 'data' => ['usage' => ['inputTokens' => 1, 'outputTokens' => 1]]],
    ]);

    $events = collectEvents($handler->handle(createTextRequest()));

    $textDeltas = array_values(array_filter($events, fn ($e) => $e instanceof TextDeltaEvent));
    expect($textDeltas)->toHaveCount(1);
    expect($textDeltas[0]->delta)->toBe('Hello');
});

it('ignores unknown event types', function (): void {
    $handler = createConverseStreamHandler([
        ['type' => 'messageStart', 'data' => ['model' => 'amazon.nova-micro-v1:0']],
        ['type' => 'unknownEvent', 'data' => ['foo' => 'bar']],
        ['type' => 'contentBlockStart', 'data' => ['contentBlockIndex' => 0, 'start' => []]],
        ['type' => 'contentBlockDelta', 'data' => ['delta' => ['text' => 'Hello']]],
        ['type' => 'contentBlockStop', 'data' => ['contentBlockIndex' => 0]],
        ['type' => 'messageStop', 'data' => ['stopReason' => 'end_turn']],
        ['type' => 'metadata', 'data' => ['usage' => ['inputTokens' => 1, 'outputTokens' => 1]]],
    ]);

    $events = collectEvents($handler->handle(createTextRequest()));

    $textDeltas = array_values(array_filter($events, fn ($e) => $e instanceof TextDeltaEvent));
    expect($textDeltas)->toHaveCount(1);
});
