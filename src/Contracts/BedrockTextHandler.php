<?php

namespace Clinically\PrismBedrock\Contracts;

use Illuminate\Http\Client\PendingRequest;
use Clinically\PrismBedrock\Bedrock;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;

abstract class BedrockTextHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected PendingRequest $client
    ) {}

    abstract public function handle(Request $request): Response;
}
