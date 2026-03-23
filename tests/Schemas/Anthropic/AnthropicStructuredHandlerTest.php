<?php

declare(strict_types=1);

namespace Tests\Schemas\Anthropic;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Facades\Tool;
use Tests\Fixtures\FixtureResponse;

it('returns structured output', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'anthropic/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys([
        'weather',
        'game_time',
        'coat_required',
    ]);
    expect($response->structured['weather'])->toBeString();
    expect($response->structured['game_time'])->toBeString();
    expect($response->structured['coat_required'])->toBeBool();
});

it('uses custom jsonModeMessage when provided via providerOptions', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'anthropic/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $customMessage = 'Please return a JSON response using this custom format instruction';

    Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withProviderOptions([
            'jsonModeMessage' => $customMessage,
        ])
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    Http::assertSent(function (Request $request) use ($customMessage): bool {
        $messages = $request->data()['messages'] ?? [];
        $lastMessage = end($messages);

        return isset($lastMessage['content'][0]['text']) &&
               str_contains((string) $lastMessage['content'][0]['text'], $customMessage);
    });
});

it('uses default jsonModeMessage when no custom message is provided', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'anthropic/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $defaultMessage = 'Respond with ONLY JSON (i.e. not in backticks or a code block, with NO CONTENT outside the JSON) that matches the following schema:';

    Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withSystemPrompt('The tigers game is at 3pm and the temperature will be 70º')
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    Http::assertSent(function (Request $request) use ($defaultMessage): bool {
        $messages = $request->data()['messages'] ?? [];
        $lastMessage = end($messages);

        return isset($lastMessage['content'][0]['text']) &&
               str_contains((string) $lastMessage['content'][0]['text'], $defaultMessage);
    });
});

it('can generate structured output using tools', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'anthropic/structured-with-multiple-tools');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'the city you want the weather for')
            ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
        Tool::as('search')
            ->for('useful for searching current events or data')
            ->withStringParameter('query', 'The detailed search query')
            ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ];

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    $response = Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withTools($tools)
        ->withMaxSteps(3)
        ->withPrompt('What time is the tigers game today and should I wear a coat?')
        ->asStructured();

    expect($response->steps)->toHaveCount(3);

    expect($response->steps[0]->toolCalls)->toHaveCount(1);
    expect($response->steps[0]->toolCalls[0]->name)->toBe('search');

    expect($response->steps[1]->toolCalls)->toHaveCount(1);
    expect($response->steps[1]->toolCalls[0]->name)->toBe('weather');

    expect($response->structured)->toBeArray();
    expect($response->structured)->toHaveKeys(['weather', 'game_time', 'coat_required']);
    expect($response->structured['coat_required'])->toBeFalse();
});

it('includes tools in the request payload', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'anthropic/structured');

    $tools = [
        Tool::as('weather')
            ->for('useful when you need to search for current weather conditions')
            ->withStringParameter('city', 'the city you want the weather for')
            ->using(fn (string $city): string => 'sunny'),
    ];

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
        ],
        ['weather']
    );

    Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withTools($tools)
        ->withPrompt('What is the weather?')
        ->asStructured();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        expect($data)->toHaveKey('tools');
        expect($data['tools'])->toHaveCount(1);
        expect($data['tools'][0]['name'])->toBe('weather');

        return true;
    });
});

it('does not remove 0 values from payloads', function (): void {
    FixtureResponse::fakeResponseSequence('invoke', 'anthropic/structured');

    $schema = new ObjectSchema(
        'output',
        'the output object',
        [
            new StringSchema('weather', 'The weather forecast'),
            new StringSchema('game_time', 'The tigers game time'),
            new BooleanSchema('coat_required', 'whether a coat is required'),
        ],
        ['weather', 'game_time', 'coat_required']
    );

    Prism::structured()
        ->withSchema($schema)
        ->using('bedrock', 'anthropic.claude-3-5-haiku-20241022-v1:0')
        ->withProviderOptions([
            'guardRailConfig' => null,
        ])
        ->withMaxTokens(2048)
        ->usingTemperature(0)
        ->asStructured();

    Http::assertSent(function (Request $request): bool {
        expect($request->data())->toMatchArray([
            'temperature' => 0,
        ]);

        return true;
    });
});
