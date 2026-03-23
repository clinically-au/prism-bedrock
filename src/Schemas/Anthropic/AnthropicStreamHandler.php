<?php

declare(strict_types=1);

namespace Prism\Bedrock\Schemas\Anthropic;

use Generator;
use Illuminate\Http\Client\Response;
use Prism\Bedrock\Concerns\ParsesEventStream;
use Prism\Bedrock\Contracts\BedrockStreamHandler;
use Prism\Bedrock\Schemas\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class AnthropicStreamHandler extends BedrockStreamHandler
{
    use CallsTools, ParsesEventStream;

    protected StreamState $state;

    /** @return Generator<StreamEvent> */
    #[\Override]
    public function handle(Request $request): Generator
    {
        $this->state = new StreamState;

        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /** @return Generator<StreamEvent> */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        foreach ($this->iterateEventStream($response) as $event) {
            $innerEvent = $this->decodeAnthropicEvent($event['data']);

            if ($innerEvent === null) {
                continue;
            }

            $result = $this->processEvent($innerEvent);

            if ($result instanceof Generator) {
                yield from $result;
            } elseif ($result instanceof StreamEvent) {
                yield $result;
            }
        }

        if ($this->state->hasToolCalls()) {
            yield from $this->handleToolCalls($request, $depth);

            return;
        }

        $this->state->markStepFinished();

        yield new StepFinishEvent(
            id: EventID::generate(),
            timestamp: time()
        );

        yield $this->emitStreamEndEvent();
    }

    /**
     * Decode the inner Anthropic event from a Bedrock event stream frame.
     *
     * Bedrock wraps Anthropic streaming events in a binary event stream where each
     * frame's payload contains `{ "bytes": "base64-encoded-json" }`.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeAnthropicEvent(array $data): ?array
    {
        $bytes = $data['bytes'] ?? null;

        if ($bytes === null) {
            return null;
        }

        $decoded = base64_decode($bytes, true);

        if ($decoded === false) {
            return null;
        }

        $event = json_decode($decoded, true);

        if (! is_array($event)) {
            return null;
        }

        return $event;
    }

    /** @return StreamEvent|Generator<StreamEvent>|null */
    protected function processEvent(array $event): StreamEvent|Generator|null
    {
        return match ($event['type'] ?? null) {
            'message_start' => $this->handleMessageStart($event),
            'content_block_start' => $this->handleContentBlockStart($event),
            'content_block_delta' => $this->handleContentBlockDelta($event),
            'content_block_stop' => $this->handleContentBlockStop($event),
            'message_delta' => $this->handleMessageDelta($event),
            'message_stop' => $this->handleMessageStop(),
            'ping' => null,
            default => null,
        };
    }

    /** @return Generator<StreamEvent> */
    protected function handleMessageStart(array $event): Generator
    {
        $message = $event['message'] ?? [];
        $this->state->withMessageId($message['id'] ?? EventID::generate());

        $usageData = $message['usage'] ?? [];

        if ($usageData !== []) {
            $this->state->addUsage(new Usage(
                promptTokens: $usageData['input_tokens'] ?? 0,
                completionTokens: $usageData['output_tokens'] ?? 0,
                cacheWriteInputTokens: $usageData['cache_creation_input_tokens'] ?? null,
                cacheReadInputTokens: $usageData['cache_read_input_tokens'] ?? null
            ));
        }

        if ($this->state->shouldEmitStreamStart()) {
            $this->state->markStreamStarted();

            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: $message['model'] ?? 'unknown',
                provider: 'bedrock'
            );
        }

        if ($this->state->shouldEmitStepStart()) {
            $this->state->markStepStarted();

            yield new StepStartEvent(
                id: EventID::generate(),
                timestamp: time()
            );
        }
    }

    protected function handleContentBlockStart(array $event): ?StreamEvent
    {
        $contentBlock = $event['content_block'] ?? [];
        $index = $event['index'] ?? 0;

        $this->state->withBlockContext($index, $contentBlock['type'] ?? '');

        return match ($this->state->currentBlockType()) {
            'text' => new TextStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                messageId: $this->state->messageId()
            ),
            'tool_use' => $this->handleToolUseStart($contentBlock),
            default => null,
        };
    }

    protected function handleToolUseStart(array $contentBlock): null
    {
        if ($this->state->currentBlockIndex() !== null) {
            $this->state->addToolCall($this->state->currentBlockIndex(), [
                'id' => $contentBlock['id'] ?? EventID::generate(),
                'name' => $contentBlock['name'] ?? 'unknown',
                'input' => '',
            ]);
        }

        return null;
    }

    protected function handleContentBlockDelta(array $event): ?StreamEvent
    {
        $delta = $event['delta'] ?? [];
        $deltaType = $delta['type'] ?? null;

        return match ([$this->state->currentBlockType(), $deltaType]) {
            ['text', 'text_delta'] => $this->handleTextDelta($delta),
            ['tool_use', 'input_json_delta'] => $this->handleToolInputDelta($delta),
            default => null,
        };
    }

    protected function handleTextDelta(array $delta): ?TextDeltaEvent
    {
        $text = $delta['text'] ?? '';

        if ($text === '') {
            return null;
        }

        $this->state->appendText($text);

        return new TextDeltaEvent(
            id: EventID::generate(),
            timestamp: time(),
            delta: $text,
            messageId: $this->state->messageId()
        );
    }

    protected function handleToolInputDelta(array $delta): ?ToolCallDeltaEvent
    {
        $partialJson = $delta['partial_json'] ?? '';

        if ($this->state->currentBlockIndex() === null) {
            return null;
        }

        $toolCalls = $this->state->toolCalls();

        if (! isset($toolCalls[$this->state->currentBlockIndex()])) {
            return null;
        }

        $this->state->appendToolCallInput($this->state->currentBlockIndex(), $partialJson);

        $toolCall = $toolCalls[$this->state->currentBlockIndex()];

        return new ToolCallDeltaEvent(
            id: EventID::generate(),
            timestamp: time(),
            toolId: $toolCall['id'],
            toolName: $toolCall['name'],
            delta: $partialJson,
            messageId: $this->state->messageId()
        );
    }

    protected function handleContentBlockStop(array $event): ?StreamEvent
    {
        $result = match ($this->state->currentBlockType()) {
            'text' => $this->handleTextComplete(),
            'tool_use' => $this->handleToolUseComplete(),
            default => null,
        };

        $this->state->resetBlockContext();

        return $result;
    }

    protected function handleTextComplete(): TextCompleteEvent
    {
        $this->state->markTextCompleted();

        return new TextCompleteEvent(
            id: EventID::generate(),
            timestamp: time(),
            messageId: $this->state->messageId()
        );
    }

    protected function handleToolUseComplete(): ?ToolCallEvent
    {
        if ($this->state->currentBlockIndex() === null) {
            return null;
        }

        $toolCalls = $this->state->toolCalls();

        if (! isset($toolCalls[$this->state->currentBlockIndex()])) {
            return null;
        }

        $toolCall = $toolCalls[$this->state->currentBlockIndex()];
        $input = $toolCall['input'];

        if (is_string($input) && json_validate($input)) {
            $input = json_decode($input, true);
        } elseif (is_string($input) && $input !== '') {
            $input = ['input' => $input];
        } else {
            $input = [];
        }

        return new ToolCallEvent(
            id: EventID::generate(),
            timestamp: time(),
            toolCall: new ToolCall(
                id: $toolCall['id'],
                name: $toolCall['name'],
                arguments: $input
            ),
            messageId: $this->state->messageId()
        );
    }

    protected function handleMessageDelta(array $event): null
    {
        $delta = $event['delta'] ?? [];
        $stopReason = $delta['stop_reason'] ?? null;

        if ($stopReason !== null) {
            $this->state->withFinishReason(FinishReasonMap::map($stopReason));
        }

        $usageData = $event['usage'] ?? [];

        if ($usageData !== [] && $this->state->usage() instanceof Usage && isset($usageData['output_tokens'])) {
            $currentUsage = $this->state->usage();

            $this->state->withUsage(new Usage(
                promptTokens: $currentUsage->promptTokens,
                completionTokens: $usageData['output_tokens'],
                cacheWriteInputTokens: $currentUsage->cacheWriteInputTokens,
                cacheReadInputTokens: $currentUsage->cacheReadInputTokens
            ));
        }

        return null;
    }

    protected function handleMessageStop(): null
    {
        if (! $this->state->finishReason() instanceof FinishReason) {
            $this->state->withFinishReason(FinishReason::Stop);
        }

        return null;
    }

    /** @return Generator<StreamEvent> */
    protected function handleToolCalls(Request $request, int $depth): Generator
    {
        $toolCalls = $this->buildToolCallObjects();

        $toolResults = [];
        yield from $this->callToolsAndYieldEvents($request->tools(), $toolCalls, $this->state->messageId(), $toolResults);

        if ($toolResults !== []) {
            $request->addMessage(new AssistantMessage(
                content: $this->state->currentText(),
                toolCalls: $toolCalls
            ));

            $request->addMessage(new ToolResultMessage($toolResults));
            $request->resetToolChoice();

            $this->state->markStepFinished();

            yield new StepFinishEvent(
                id: EventID::generate(),
                timestamp: time()
            );

            $depth++;

            if ($depth < $request->maxSteps()) {
                $this->state->reset();

                $nextResponse = $this->sendRequest($request);

                yield from $this->processStream($nextResponse, $request, $depth);
            } else {
                yield $this->emitStreamEndEvent();
            }
        }
    }

    /** @return ToolCall[] */
    protected function buildToolCallObjects(): array
    {
        $toolCalls = [];

        foreach ($this->state->toolCalls() as $toolCallData) {
            $input = $toolCallData['input'];

            if (is_string($input) && json_validate($input)) {
                $input = json_decode($input, true);
            } elseif (is_string($input) && $input !== '') {
                $input = ['input' => $input];
            } else {
                $input = [];
            }

            $toolCalls[] = new ToolCall(
                id: $toolCallData['id'],
                name: $toolCallData['name'],
                arguments: $input
            );
        }

        return $toolCalls;
    }

    protected function emitStreamEndEvent(): StreamEndEvent
    {
        return new StreamEndEvent(
            id: EventID::generate(),
            timestamp: time(),
            finishReason: $this->state->finishReason() ?? FinishReason::Stop,
            usage: $this->state->usage()
        );
    }

    protected function sendRequest(Request $request): Response
    {
        try {
            /** @var Response $response */
            $response = $this->client
                ->withOptions(['stream' => true])
                ->post(
                    'invoke-with-response-stream',
                    AnthropicTextHandler::buildPayload($request, $this->provider->apiVersion($request))
                );

            return $response;
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }
}
