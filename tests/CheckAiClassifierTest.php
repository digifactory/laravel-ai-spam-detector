<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'ai.providers.typesafe.key' => 'test-key',
        'ai-spam-detector.provider' => 'typesafe',
        'ai-spam-detector.model' => 'jev-latest',
        'ai-spam-detector.timeout' => 5,
    ]);
    Http::preventStrayRequests();
});

test('checks the classifier with exactly one minimal API request', function () {
    Http::fake(['api.typesafe.ai/*' => Http::response([
        'answers' => ['health' => ['type' => 'noul', 'noul' => 0.99]],
        'model' => 'jev-latest',
    ])]);

    $this->artisan('ai:check-classifier')
        ->expectsOutputToContain('Classifier is responding')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.typesafe.ai/v1/systemone'
        && $request['model'] === 'jev-latest'
        && $request['state'] === 'The sky is blue.'
        && count($request['questions']) === 1
    );
});

test('reports a connection timeout and exits unsuccessfully', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $this->artisan('ai:check-classifier', ['--timeout' => 2])
        ->expectsOutputToContain('timeout: 2s')
        ->expectsOutputToContain('Classifier check failed')
        ->expectsOutputToContain('Connection timed out')
        ->assertExitCode(1);
});

test('rejects a malformed classifier answer', function () {
    Http::fake(['api.typesafe.ai/*' => Http::response(['answers' => []])]);

    $this->artisan('ai:check-classifier')
        ->expectsOutputToContain('No answer was returned')
        ->assertExitCode(1);
});

test('rejects invalid timeouts without an API request', function () {
    $this->artisan('ai:check-classifier', ['--timeout' => 0])
        ->expectsOutputToContain('Timeout must be a positive number')
        ->assertExitCode(2);

    Http::assertNothingSent();
});
