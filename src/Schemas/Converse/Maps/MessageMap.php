<?php

declare(strict_types=1);

namespace Clinically\PrismBedrock\Schemas\Converse\Maps;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

class MessageMap
{
    /**
     * @param  array<int, Message>  $messages
     * @return array<int, mixed>
     */
    public static function map(array $messages): array
    {
        if (array_filter($messages, fn (Message $message): bool => $message instanceof SystemMessage) !== []) {
            throw new PrismException('Bedrock Converse API does not support SystemMessages in the messages array. Use withSystemPrompt or withSystemPrompts instead.');
        }

        $mapped = array_map(
            self::mapMessage(...),
            $messages
        );

        return self::mergeConsecutiveSameRoleMessages($mapped);
    }

    /**
     * Merge consecutive messages with the same role by concatenating their content arrays.
     *
     * The Bedrock Converse API requires strict role alternation (user, assistant, user, ...).
     * When conversation history is replayed, a ToolResultMessage (role: user) may be followed
     * by a UserMessage (role: user), producing two consecutive user-role messages. This method
     * merges them into a single message with combined content blocks.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected static function mergeConsecutiveSameRoleMessages(array $messages): array
    {
        if (count($messages) <= 1) {
            return $messages;
        }

        $merged = [];
        $current = null;

        foreach ($messages as $message) {
            if ($current === null) {
                $current = $message;

                continue;
            }

            if ($current['role'] === $message['role']) {
                $current['content'] = array_merge($current['content'], $message['content']);

                continue;
            }

            $merged[] = $current;
            $current = $message;
        }

        if ($current !== null) {
            $merged[] = $current;
        }

        return $merged;
    }

    /**
     * @param  SystemMessage[]  $systemPrompts
     * @return array<int, mixed>
     */
    public static function mapSystemMessages(array $systemPrompts): array
    {
        $output = [];

        foreach ($systemPrompts as $prompt) {
            $output[] = self::mapSystemMessage($prompt);

            $cacheType = data_get($prompt->providerOptions(), 'cacheType');

            if ($cacheType) {
                $output[] = ['cachePoint' => ['type' => $cacheType]];
            }
        }

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapMessage(Message $message): array
    {
        return match ($message::class) {
            UserMessage::class => self::mapUserMessage($message),
            AssistantMessage::class => self::mapAssistantMessage($message),
            ToolResultMessage::class => self::mapToolResultMessage($message),
            SystemMessage::class => self::mapSystemMessage($message),
            default => throw new PrismException('Converse: Could not map message type '.$message::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapSystemMessage(SystemMessage $systemMessage): array
    {
        return ['text' => $systemMessage->content];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapToolResultMessage(ToolResultMessage $message): array
    {
        return [
            'role' => 'user',
            'content' => array_map(fn (ToolResult $toolResult): array => [
                'toolResult' => [
                    'status' => $toolResult->result !== null ? 'success' : 'error',
                    'toolUseId' => $toolResult->toolCallId,
                    'content' => [
                        [
                            'text' => $toolResult->result,
                        ],
                    ],
                ],
            ], $message->toolResults),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapUserMessage(UserMessage $message): array
    {
        $cacheType = data_get($message->providerOptions(), 'cacheType');

        return [
            'role' => 'user',
            'content' => array_filter([
                ['text' => $message->text()],
                ...self::mapImageParts($message->images()),
                ...self::mapDocumentParts($message->documents()),
                $cacheType ? ['cachePoint' => ['type' => $cacheType]] : null,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapAssistantMessage(AssistantMessage $message): array
    {
        $cacheType = data_get($message->providerOptions(), 'cacheType');

        return [
            'role' => 'assistant',
            'content' => array_values(array_filter([
                $message->content === '' || $message->content === '0' ? null : ['text' => $message->content],
                ...self::mapToolCalls($message->toolCalls),
                $cacheType ? ['cachePoint' => ['type' => $cacheType]] : null,
            ])),
        ];
    }

    /**
     * @param  ToolCall[]  $parts
     * @return array<int, mixed>
     */
    protected static function mapToolCalls(array $parts): array
    {
        return array_map(fn (ToolCall $toolCall): array => [
            'toolUse' => [
                'toolUseId' => $toolCall->id,
                'name' => $toolCall->name,
                'input' => $toolCall->arguments() === [] ? new \stdClass : $toolCall->arguments(),
            ],
        ], $parts);
    }

    /**
     * @param  Image[]  $parts
     * @return array<int, mixed>
     */
    protected static function mapImageParts(array $parts): array
    {
        return array_map(
            fn (Image $image): array => (new ImageMapper($image))->toPayload(),
            $parts
        );
    }

    /**
     * @param  Document[]  $parts
     * @return array<string,array<string,mixed>>
     */
    protected static function mapDocumentParts(array $parts): array
    {
        return array_map(
            fn (Document $document): array => (new DocumentMapper($document))->toPayload(),
            $parts
        );
    }
}
