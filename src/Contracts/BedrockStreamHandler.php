<?php

declare(strict_types=1);

namespace Prism\Bedrock\Contracts;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Prism\Bedrock\Bedrock;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Text\Request;

abstract class BedrockStreamHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected PendingRequest $client
    ) {}

    /** @return Generator<StreamEvent> */
    abstract public function handle(Request $request): Generator;
}
