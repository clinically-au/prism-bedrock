<?php

namespace Prism\Bedrock\Schemas\Converse;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Prism\Bedrock\Contracts\BedrockStructuredHandler;
use Prism\Bedrock\Schemas\Converse\Concerns\ExtractsText;
use Prism\Bedrock\Schemas\Converse\Concerns\ExtractsThinking;
use Prism\Bedrock\Schemas\Converse\Concerns\ExtractsToolCalls;
use Prism\Bedrock\Schemas\Converse\Maps\FinishReasonMap;
use Prism\Bedrock\Schemas\Converse\Maps\MessageMap;
use Prism\Bedrock\Schemas\Converse\Maps\ToolChoiceMap;
use Prism\Bedrock\Schemas\Converse\Maps\ToolMap;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Structured\Request;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class ConverseStructuredHandler extends BedrockStructuredHandler
{
    use CallsTools, ExtractsText, ExtractsThinking, ExtractsToolCalls;

    protected StructuredResponse $tempResponse;

    protected Response $httpResponse;

    protected ResponseBuilder $responseBuilder;

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->responseBuilder = new ResponseBuilder;
    }

    #[\Override]
    public function handle(Request $request): StructuredResponse
    {
        if ($this->responseBuilder->steps->isEmpty()) {
            $this->appendMessageForJsonMode($request);
        }

        $this->sendRequest($request);

        $this->prepareTempResponse();

        $responseMessage = new AssistantMessage(
            content: $this->tempResponse->text,
            toolCalls: $this->tempResponse->toolCalls,
            additionalContent: $this->tempResponse->additionalContent
        );

        $request->addMessage($responseMessage);

        return match ($this->tempResponse->finishReason) {
            FinishReason::ToolCalls => $this->handleToolCalls($request),
            default => $this->handleStop($request),
        };
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildPayload(Request $request, int $stepCount = 0): array
    {
        return array_filter([
            'additionalModelRequestFields' => $request->providerOptions('additionalModelRequestFields'),
            'additionalModelResponseFieldPaths' => $request->providerOptions('additionalModelResponseFieldPaths'),
            'guardrailConfig' => $request->providerOptions('guardrailConfig'),
            'inferenceConfig' => array_filter([
                'maxTokens' => $request->maxTokens(),
                'temperature' => $request->temperature(),
                'topP' => $request->topP(),
            ], fn (mixed $value): bool => $value !== null),
            'messages' => MessageMap::map($request->messages()),
            'toolConfig' => $request->tools() === []
                ? null
                : array_filter([
                    'tools' => ToolMap::map($request->tools()),
                    'toolChoice' => $stepCount === 0 ? ToolChoiceMap::map($request->toolChoice()) : null,
                ]),
            'performanceConfig' => $request->providerOptions('performanceConfig'),
            'promptVariables' => $request->providerOptions('promptVariables'),
            'requestMetadata' => $request->providerOptions('requestMetadata'),
            'system' => MessageMap::mapSystemMessages($request->systemPrompts()),
        ]);
    }

    protected function sendRequest(Request $request): void
    {
        try {
            $this->httpResponse = $this->client->post(
                'converse',
                static::buildPayload($request, $this->responseBuilder->steps->count())
            );
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($request->model(), $e);
        }
    }

    protected function prepareTempResponse(): void
    {
        $data = $this->httpResponse->json();

        $this->tempResponse = new StructuredResponse(
            steps: new Collection,
            text: $this->extractText($data),
            structured: [],
            finishReason: FinishReasonMap::map(data_get($data, 'stopReason')),
            toolCalls: $this->extractToolCalls($data),
            usage: new Usage(
                promptTokens: data_get($data, 'usage.inputTokens'),
                completionTokens: data_get($data, 'usage.outputTokens'),
                cacheWriteInputTokens: data_get($data, 'usage.cacheWriteInputTokenCount'),
                cacheReadInputTokens: data_get($data, 'usage.cacheReadInputTokenCount'),
            ),
            meta: new Meta(id: '', model: ''), // Not provided in Converse response.
            additionalContent: $this->extractThinking($data),
        );
    }

    protected function handleToolCalls(Request $request): StructuredResponse
    {
        $toolResults = $this->callTools($request->tools(), $this->tempResponse->toolCalls);
        $message = new ToolResultMessage($toolResults);

        $request->addMessage($message);

        $this->addStep($request, $toolResults);

        if ($this->shouldContinue($request)) {
            return $this->handle($request);
        }

        return $this->responseBuilder->toResponse();
    }

    protected function handleStop(Request $request): StructuredResponse
    {
        $this->addStep($request);

        return $this->responseBuilder->toResponse();
    }

    protected function shouldContinue(Request $request): bool
    {
        return $this->responseBuilder->steps->count() < $request->maxSteps();
    }

    /**
     * @param  ToolResult[]  $toolResults
     */
    protected function addStep(Request $request, array $toolResults = []): void
    {
        $this->responseBuilder->addStep(new Step(
            text: $this->tempResponse->text,
            finishReason: $this->tempResponse->finishReason,
            usage: $this->tempResponse->usage,
            meta: $this->tempResponse->meta,
            messages: $request->messages(),
            systemPrompts: $request->systemPrompts(),
            additionalContent: $this->tempResponse->additionalContent,
            toolCalls: $this->tempResponse->toolCalls,
            toolResults: $toolResults,
        ));
    }

    protected function appendMessageForJsonMode(Request $request): void
    {
        $request->addMessage(new UserMessage(sprintf(
            "%s \n %s",
            $request->providerOptions('jsonModeMessage') ?? 'Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema:',
            json_encode($request->schema()->toArray(), JSON_PRETTY_PRINT)
        )));
    }
}
