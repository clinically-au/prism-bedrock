<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse\Maps;

use Clinically\PrismBedrock\Schemas\Converse\Maps\MessageMap;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

it('maps user messages', function (): void {
    expect(MessageMap::map([
        new UserMessage('Who are you?'),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['text' => 'Who are you?'],
        ],
    ]]);
});

it('maps assistant message', function (): void {
    expect(MessageMap::map([
        new AssistantMessage('I am Nyx'),
    ]))->toContain([
        'role' => 'assistant',
        'content' => [
            [
                'text' => 'I am Nyx',
            ],
        ],
    ]);
});

it('maps system messages', function (): void {
    expect(MessageMap::mapSystemMessages([
        new SystemMessage('I am Thanos.'),
        new SystemMessage('But call me Bob.'),
    ]))->toBe([
        [
            'text' => 'I am Thanos.',
        ],
        [
            'text' => 'But call me Bob.',
        ],
    ]);
});

it('maps an md document correctly', function (): void {
    expect(MessageMap::map([
        new UserMessage(
            content: 'Who are you?',
            additionalContent: [
                Document::fromPath('tests/Fixtures/document.md', 'Answer To Life'),
            ]
        ),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['text' => 'Who are you?'],
            [
                'document' => [
                    'format' => 'txt',
                    'name' => 'Answer To Life',
                    'source' => ['bytes' => base64_encode(file_get_contents('tests/Fixtures/document.md'))],
                ],
            ],
        ],
    ]]);
});

it('maps an image correctly', function (): void {
    expect(MessageMap::map([
        new UserMessage(
            content: 'Who are you?',
            additionalContent: [
                Image::fromPath('tests/Fixtures/test-image.png'),
            ]
        ),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['text' => 'Who are you?'],
            [
                'image' => [
                    'format' => 'png',
                    'source' => ['bytes' => base64_encode(file_get_contents('tests/Fixtures/test-image.png'))],
                ],
            ],
        ],
    ]]);
});

it('maps assistant message with tool calls', function (): void {
    expect(MessageMap::map([
        new AssistantMessage('I am Nyx', [
            new ToolCall(
                'tool_1234',
                'search',
                [
                    'query' => 'Laravel collection methods',
                ]
            ),
        ]),
    ]))->toBe([
        [
            'role' => 'assistant',
            'content' => [
                ['text' => 'I am Nyx'],
                [
                    'toolUse' => [
                        'toolUseId' => 'tool_1234',
                        'name' => 'search',
                        'input' => [
                            'query' => 'Laravel collection methods',
                        ],
                    ],
                ],
            ],
        ],
    ]);
});

it('maps assistant message with tool calls with empty arguments as stdClass', function (): void {
    expect(MessageMap::map([
        new AssistantMessage('Running tool', [
            new ToolCall(
                'tool_5678',
                'get_time',
                []
            ),
        ]),
    ]))->toEqual([
        [
            'role' => 'assistant',
            'content' => [
                ['text' => 'Running tool'],
                [
                    'toolUse' => [
                        'toolUseId' => 'tool_5678',
                        'name' => 'get_time',
                        'input' => new \stdClass,
                    ],
                ],
            ],
        ],
    ]);
});

it('maps tool result messages', function (): void {
    expect(MessageMap::map([
        new ToolResultMessage([
            new ToolResult(
                'tool_1234',
                'search',
                [
                    'query' => 'Laravel collection methods',
                ],
                '[search results]'
            ),
        ]),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            [
                'toolResult' => [
                    'status' => 'success',
                    'toolUseId' => 'tool_1234',
                    'content' => [
                        ['text' => '[search results]'],
                    ],
                ],
            ],
        ],
    ]]);
});

it('maps user messages with a cache breakpoint correctly', function (): void {
    expect(MessageMap::map([
        (new UserMessage('Who are you?'))->withProviderOptions(['cacheType' => 'default']),
    ]))->toBe([[
        'role' => 'user',
        'content' => [
            ['text' => 'Who are you?'],
            [
                'cachePoint' => [
                    'type' => 'default',
                ],
            ],
        ],
    ]]);
});

it('maps assistant messages with a cache breakpoint correctly', function (): void {
    expect(MessageMap::map([
        (new AssistantMessage('I am Thanos'))->withProviderOptions(['cacheType' => 'default']),
    ]))->toBe([[
        'role' => 'assistant',
        'content' => [
            ['text' => 'I am Thanos'],
            [
                'cachePoint' => [
                    'type' => 'default',
                ],
            ],
        ],
    ]]);
});

it('maps system messages with a cache breakpoint correctly', function (): void {
    expect(MessageMap::mapSystemMessages([
        (new SystemMessage('The answer to life is 42.'))->withProviderOptions(['cacheType' => 'default']),
        (new SystemMessage('Convert any numbers in your answer to their word format.')),
    ]))->toBe([
        [
            'text' => 'The answer to life is 42.',
        ],
        [
            'cachePoint' => [
                'type' => 'default',
            ],
        ],
        [
            'text' => 'Convert any numbers in your answer to their word format.',
        ],
    ]);
});

it('merges consecutive user messages (tool result followed by user message)', function (): void {
    expect(MessageMap::map([
        new UserMessage('Hello'),
        new AssistantMessage('Let me look that up', [
            new ToolCall('tool_1', 'search', ['query' => 'test']),
        ]),
        new ToolResultMessage([
            new ToolResult('tool_1', 'search', ['query' => 'test'], 'result here'),
        ]),
        new UserMessage('Thanks, now do something else'),
    ]))->toBe([
        [
            'role' => 'user',
            'content' => [
                ['text' => 'Hello'],
            ],
        ],
        [
            'role' => 'assistant',
            'content' => [
                ['text' => 'Let me look that up'],
                [
                    'toolUse' => [
                        'toolUseId' => 'tool_1',
                        'name' => 'search',
                        'input' => ['query' => 'test'],
                    ],
                ],
            ],
        ],
        [
            'role' => 'user',
            'content' => [
                [
                    'toolResult' => [
                        'status' => 'success',
                        'toolUseId' => 'tool_1',
                        'content' => [
                            ['text' => 'result here'],
                        ],
                    ],
                ],
                ['text' => 'Thanks, now do something else'],
            ],
        ],
    ]);
});

it('does not merge messages when roles alternate correctly', function (): void {
    expect(MessageMap::map([
        new UserMessage('Hello'),
        new AssistantMessage('Hi there'),
        new UserMessage('How are you?'),
    ]))->toBe([
        [
            'role' => 'user',
            'content' => [
                ['text' => 'Hello'],
            ],
        ],
        [
            'role' => 'assistant',
            'content' => [
                ['text' => 'Hi there'],
            ],
        ],
        [
            'role' => 'user',
            'content' => [
                ['text' => 'How are you?'],
            ],
        ],
    ]);
});
