<?php

namespace Clinically\PrismBedrock\Contracts;

use Illuminate\Http\Client\PendingRequest;
use Clinically\PrismBedrock\Bedrock;
use Prism\Prism\Images\Request;
use Prism\Prism\Images\Response;

abstract class BedrockImagesHandler
{
    public function __construct(
        protected Bedrock $provider,
        protected PendingRequest $client
    ) {}

    abstract public function handle(Request $request): Response;
}
