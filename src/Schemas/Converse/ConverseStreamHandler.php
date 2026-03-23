<?php

declare(strict_types=1);

namespace Prism\Bedrock\Schemas\Converse;

use Generator;
use Illuminate\Http\Client\Response;
use Prism\Bedrock\Concerns\ParsesEventStream;
use Prism\Bedrock\Contracts\BedrockStreamHandler;
use Prism\Bedrock\Schemas\Converse\Maps\FinishReasonMap;
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
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\StreamState;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class ConverseStreamHandler extends BedrockStreamHandler
{
    use CallsTools, ParsesEventStream;

    protected StreamState $state;

    protected int $stepCount = 0;

    /** @return Generator<StreamEvent> */
    #[\Override]
    public function handle(Request $request): Generator
    {
        $this->state = new StreamState;
        $this->stepCount = 0;

        $response = $this->sendRequest($request);

        yield from $this->processStream($response, $request);
    }

    /** @return Generator<StreamEvent> */
    protected function processStream(Response $response, Request $request, int $depth = 0): Generator
    {
        foreach ($this->iterateEventStream($response) as $event) {
            $result = $this->processEvent($event['type'], $event['data']);

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

    /** @return StreamEvent|Generator<StreamEvent>|null */
    protected function processEvent(string $eventType, array $data): StreamEvent|Generator|null
    {
        return match ($eventType) {
            'messageStart' => $this->handleMessageStart($data),
            'contentBlockStart' => $this->handleContentBlockStart($data),
            'contentBlockDelta' => $this->handleContentBlockDelta($data),
            'contentBlockStop' => $this->handleContentBlockStop($data),
            'messageStop' => $this->handleMessageStop($data),
            'metadata' => $this->handleMetadata($data),
            default => null,
        };
    }

    /** @return Generator<StreamEvent> */
    protected function handleMessageStart(array $data): Generator
    {
        $this->state->withMessageId(EventID::generate());

        if ($this->state->shouldEmitStreamStart()) {
            $this->state->markStreamStarted();

            yield new StreamStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                model: $data['model'] ?? 'unknown',
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

    protected function handleContentBlockStart(array $data): ?StreamEvent
    {
        $start = $data['start'] ?? [];
        $index = $data['contentBlockIndex'] ?? 0;

        if (isset($start['toolUse'])) {
            $toolUse = $start['toolUse'];

            $this->state->withBlockContext($index, 'toolUse');
            $this->state->addToolCall($index, [
                'id' => $toolUse['toolUseId'] ?? EventID::generate(),
                'name' => $toolUse['name'] ?? 'unknown',
                'input' => '',
            ]);

            return null;
        }

        if (isset($start['reasoningContent'])) {
            $this->state->withBlockContext($index, 'reasoningContent');
            $this->state->withReasoningId(EventID::generate());

            return new ThinkingStartEvent(
                id: EventID::generate(),
                timestamp: time(),
                reasoningId: $this->state->reasoningId(),
            );
        }

        $this->state->withBlockContext($index, 'text');

        return new TextStartEvent(
            id: EventID::generate(),
            timestamp: time(),
            messageId: $this->state->messageId()
        );
    }

    protected function handleContentBlockDelta(array $data): ?StreamEvent
    {
        $delta = $data['delta'] ?? [];

        if (isset($delta['toolUse'])) {
            return $this->handleToolUseDelta($delta['toolUse']);
        }

        if (isset($delta['reasoningContent'])) {
            return $this->handleReasoningDelta($delta['reasoningContent']);
        }

        if (isset($delta['text'])) {
            $text = $delta['text'];

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

        return null;
    }

    protected function handleReasoningDelta(array $reasoningContent): ?ThinkingEvent
    {
        $text = $reasoningContent['text'] ?? '';

        if ($text === '') {
            return null;
        }

        $this->state->appendThinking($text);

        return new ThinkingEvent(
            id: EventID::generate(),
            timestamp: time(),
            delta: $text,
            reasoningId: $this->state->reasoningId(),
        );
    }

    protected function handleToolUseDelta(array $toolUseDelta): ?ToolCallDeltaEvent
    {
        $partialJson = $toolUseDelta['input'] ?? '';

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

    protected function handleContentBlockStop(array $data): ?StreamEvent
    {
        $result = match ($this->state->currentBlockType()) {
            'text' => $this->handleTextComplete(),
            'reasoningContent' => new ThinkingCompleteEvent(
                id: EventID::generate(),
                timestamp: time(),
                reasoningId: $this->state->reasoningId(),
            ),
            'toolUse' => $this->handleToolUseComplete(),
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

    protected function handleMessageStop(array $data): null
    {
        $stopReason = $data['stopReason'] ?? null;

        if ($stopReason !== null) {
            $this->state->withFinishReason(FinishReasonMap::map($stopReason));
        }

        return null;
    }

    protected function handleMetadata(array $data): null
    {
        $usage = $data['usage'] ?? [];

        if ($usage !== []) {
            $this->state->addUsage(new Usage(
                promptTokens: $usage['inputTokens'] ?? 0,
                completionTokens: $usage['outputTokens'] ?? 0,
                cacheWriteInputTokens: $usage['cacheWriteInputTokenCount'] ?? null,
                cacheReadInputTokens: $usage['cacheReadInputTokenCount'] ?? null,
            ));
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
                $this->stepCount++;

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
            usage: $this->state->usage(),
            additionalContent: array_filter([
                'thinking' => $this->state->thinkingSummaries() !== []
                    ? implode('', $this->state->thinkingSummaries())
                    : null,
            ]),
        );
    }

    protected function sendRequest(Request $request): Response
    {
        try {
            /** @var Response $response */
            $response = $this->client
                ->withOptions(['stream' => true])
                ->post(
                    'converse-stream',
                    ConverseTextHandler::buildPayload($request, $this->stepCount)
                );

            return $response;
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }
}
