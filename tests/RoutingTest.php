<?php

use DigiFactory\AiSpamDetector\AiSpamDetectorServiceProvider;
use DigiFactory\AiSpamDetector\Middleware\ValidateRequestWithAi;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function () {
    Classification::fake([['spam' => new BooleanAnswer(0.99), 'prompt_injection' => new BooleanAnswer(0.01)]]);
});

test('does not classify ordinary or Livewire-style posts automatically', function () {
    Route::post('/livewire/update', fn () => response('ok'));
    $this->post('/livewire/update')->assertOk()->assertContent('ok');
    Classification::assertNothingClassified();
});

test('can be attached by class and excluded from a route group', function () {
    Route::middleware(ValidateRequestWithAi::class)->group(function () {
        Route::post('/protected', fn () => response('handler'));
        Route::post('/excluded', fn () => response('ok'))->withoutMiddleware(ValidateRequestWithAi::class);
    });
    $this->post('/excluded')->assertContent('ok');
    Classification::assertNothingClassified();
    $this->post('/protected', ['message' => 'spam'])->assertOk()->assertContent('');
    Classification::assertClassified(fn ($prompt) => $prompt->state['body']['message'] === 'spam');
});

test('registers publishable config', function () {
    $paths = ServiceProvider::pathsToPublish(
        AiSpamDetectorServiceProvider::class,
        'ai-spam-detector-config'
    );
    expect($paths)->not->toBeEmpty();
});
