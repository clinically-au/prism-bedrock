<?php

namespace Tests;

use Clinically\PrismBedrock\BedrockServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            BedrockServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment($app)
    {
        tap($app['config'], function (Repository $config): void {
            $config->set('prism.providers.bedrock', [
                'api_key' => env('PRISM_BEDROCK_API_KEY', 'test-api-key'),
                'api_secret' => env('PRISM_BEDROCK_API_SECRET', 'test-api-secret'),
                'region' => env('PRISM_BEDROCK_REGION', 'us-west-2'),
            ]);
        });
    }
}
