<?php

use DigiFactory\AiSpamDetector\Events\ClassificationCompleted;
use DigiFactory\AiSpamDetector\Events\ClassificationFailed;
use DigiFactory\AiSpamDetector\Middleware\ValidateRequestWithAi;
use DigiFactory\AiSpamDetector\Rules\DoesNotClassifyAsPromptInjection;
use DigiFactory\AiSpamDetector\Rules\DoesNotClassifyAsSpam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;

function observationRejects(string $source): bool
{
    if ($source === ValidateRequestWithAi::class) {
        return app($source)->handle(Request::create('/', 'POST', ['message' => 'Text']), fn () => response('handler reached'))->getContent() !== 'handler reached';
    }

    return Validator::make(['message' => 'Text'], ['message' => [new $source]])->fails();
}

dataset('observation sources', [
    [ValidateRequestWithAi::class, 'spam'],
    [ValidateRequestWithAi::class, 'prompt_injection'],
    [DoesNotClassifyAsSpam::class, 'spam'],
    [DoesNotClassifyAsPromptInjection::class, 'prompt_injection'],
]);

beforeEach(function () {
    Event::fake([ClassificationCompleted::class, ClassificationFailed::class]);
    config(['ai-spam-detector.observe' => true]);
});

test('reports threshold decisions without blocking in observation mode', function (string $source, string $category, float $score) {
    Classification::fake([[
        'spam' => new BooleanAnswer($category === 'spam' ? $score : 0.1),
        'prompt_injection' => new BooleanAnswer($category === 'prompt_injection' ? $score : 0.1),
    ]])->preventStrayClassifications();
    expect(observationRejects($source))->toBeFalse();
    Classification::assertClassified(fn () => true);
    Event::assertDispatchedTimes(ClassificationCompleted::class, 1);
    Event::assertDispatched(ClassificationCompleted::class, fn ($event) => $event->source === $source
        && $event->observing && ! $event->blocked && $event->wouldBlock === ($score >= 0.8)
        && $event->scores[$category] === $score && $event->thresholds[$category] === 0.8
    );
    Event::assertNotDispatched(ClassificationFailed::class);
})->with('observation sources')->with([0.1, 0.8]);

test('resumes blocking when observation is disabled or absent from old configuration', function (string $source, string $category, bool $missing) {
    if ($missing) {
        $config = config('ai-spam-detector');
        unset($config['observe']);
        config(['ai-spam-detector' => $config]);
    } else {
        config(['ai-spam-detector.observe' => false]);
    }
    Classification::fake([['spam' => new BooleanAnswer(0.9), 'prompt_injection' => new BooleanAnswer(0.9)]]);
    expect(observationRejects($source))->toBeTrue();
    Event::assertDispatched(ClassificationCompleted::class, fn ($event) => ! $event->observing && $event->blocked && $event->wouldBlock);
})->with('observation sources')->with([true, false]);

test('disabled detection takes precedence over observation', function (string $source, string $category) {
    config(['ai-spam-detector.enabled' => false]);
    Classification::fake()->preventStrayClassifications();
    expect(observationRejects($source))->toBeFalse();
    Classification::assertNothingClassified();
    Event::assertNothingDispatched();
})->with('observation sources');

test('provider errors remain observable and fail open', function (string $source, string $category) {
    Classification::fake(fn () => throw new RuntimeException('Timeout'));
    expect(observationRejects($source))->toBeFalse();
    Event::assertDispatchedTimes(ClassificationFailed::class, 1);
    Event::assertNotDispatched(ClassificationCompleted::class);
})->with('observation sources');

test('preserves ordinary validation', function (string $rule) {
    Classification::fake([['spam' => new BooleanAnswer(1.0), 'prompt_injection' => new BooleanAnswer(1.0)]]);
    $validator = Validator::make(['message' => 'Text'], ['message' => [new $rule], 'name' => ['required']]);
    expect($validator->errors()->has('name'))->toBeTrue()->and($validator->errors()->has('message'))->toBeFalse();

})->with([DoesNotClassifyAsSpam::class, DoesNotClassifyAsPromptInjection::class]);

test('rejects non-text values without classifying in observation mode', function (string $rule) {
    Classification::fake()->preventStrayClassifications();
    expect(Validator::make(['message' => []], ['message' => [new $rule]])->fails())->toBeTrue();
    Classification::assertNothingClassified();
})->with([DoesNotClassifyAsSpam::class, DoesNotClassifyAsPromptInjection::class]);

test('does not swallow downstream errors in observation mode', function () {
    Classification::fake([['spam' => new BooleanAnswer(1.0), 'prompt_injection' => new BooleanAnswer(1.0)]]);
    app(ValidateRequestWithAi::class)->handle(Request::create('/', 'POST'), fn () => throw new LogicException('Application failure'));
})->throws(LogicException::class, 'Application failure');

test('does not swallow event listener errors in observation mode', function (string $source, string $category) {
    Event::except([ClassificationCompleted::class]);
    Event::listen(ClassificationCompleted::class, fn () => throw new LogicException('Listener failure'));
    Classification::fake([['spam' => new BooleanAnswer(1.0), 'prompt_injection' => new BooleanAnswer(1.0)]]);
    try {
        observationRejects($source);
        test()->fail('Expected listener failure.');
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('Listener failure');
    }
    Event::assertNotDispatched(ClassificationFailed::class);
})->with('observation sources');
