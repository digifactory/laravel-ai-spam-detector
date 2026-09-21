<?php

namespace DigiFactory\AiSpamDetector\Tests;

use DigiFactory\AiSpamDetector\AiSpamDetectorServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, AiSpamDetectorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.typesafe.key', 'test-key');
        $app['config']->set('ai-spam-detector.enabled', true);
    }
}
