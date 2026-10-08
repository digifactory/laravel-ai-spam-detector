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

function runEventClassification(string $source, string $value = 'private input'): bool
{
    if ($source === ValidateRequestWithAi::class) {
        return app($source)->handle(Request::create('/', 'POST', ['message' => $value]), fn () => response('ok'))->getContent() === '';
    }

    return Validator::make(['message' => $value], ['message' => [new $source]])->fails();
}

dataset('classification sources', [ValidateRequestWithAi::class, DoesNotClassifyAsSpam::class, DoesNotClassifyAsPromptInjection::class]);

beforeEach(function () {
    Event::fake([ClassificationCompleted::class, ClassificationFailed::class]);
    Classification::fake([[
        'spam' => new BooleanAnswer(0.9),
        'prompt_injection' => new BooleanAnswer(0.9),
    ]])->preventStrayClassifications();
});

test('emits one completion with scores and the actual decision', function (string $source, float $score) {
    Classification::fake([['spam' => new BooleanAnswer($score), 'prompt_injection' => new BooleanAnswer($score)]]);
    $blocked = runEventClassification($source);
    expect($blocked)->toBe($score >= 0.8);
    Event::assertDispatchedTimes(ClassificationCompleted::class, 1);
    Event::assertNotDispatched(ClassificationFailed::class);
    Event::assertDispatched(ClassificationCompleted::class, function ($event) use ($source, $score, $blocked) {
        $keys = match ($source) {
            DoesNotClassifyAsSpam::class => ['spam'],
            DoesNotClassifyAsPromptInjection::class => ['prompt_injection'],
            default => ['spam', 'prompt_injection'],
        };

        return $event->source === $source
            && $event->provider === 'typesafe' && $event->model === 'jev-latest'
            && $event->durationMs >= 0 && is_finite($event->durationMs)
            && $event->scores === array_fill_keys($keys, $score)
            && $event->thresholds === array_fill_keys($keys, 0.8)
            && $event->blocked === $blocked
            && ! str_contains(json_encode($event), 'private input');
    });
})->with('classification sources')->with([0.2, 0.8]);

test('emits a sanitized failure and continues on provider errors', function (string $source) {
    Classification::fake(fn () => throw new RuntimeException('private input and api-key'));
    expect(runEventClassification($source))->toBeFalse();
    Event::assertNotDispatched(ClassificationCompleted::class);
    Event::assertDispatchedTimes(ClassificationFailed::class, 1);
    Event::assertDispatched(ClassificationFailed::class, fn ($event) => $event->source === $source
        && $event->exceptionClass === RuntimeException::class
        && $event->provider === 'typesafe' && $event->model === 'jev-latest'
        && $event->durationMs >= 0
        && array_keys(get_object_vars($event)) === ['source', 'provider', 'model', 'durationMs', 'exceptionClass']
        && ! str_contains(json_encode($event), 'private input')
    );
})->with('classification sources');

test('emits no events when disabled', function (string $source) {
    config(['ai-spam-detector.enabled' => false]);
    expect(runEventClassification($source))->toBeFalse();
    Event::assertNothingDispatched();
    Classification::assertNothingClassified();
})->with('classification sources');

test('emits no events for skipped fields or methods', function () {
    foreach ([DoesNotClassifyAsSpam::class, DoesNotClassifyAsPromptInjection::class] as $rule) {
        runEventClassification($rule, '');
        runEventClassification($rule, '   ');
        Validator::make(['message' => []], ['message' => [new $rule]])->fails();
    }
    app(ValidateRequestWithAi::class)->handle(Request::create('/', 'GET'), fn () => response('ok'));
    Event::assertNothingDispatched();
    Classification::assertNothingClassified();
});

test('propagates listener exceptions without emitting a provider failure', function (string $source, bool $providerFails) {
    Event::except([ClassificationCompleted::class, ClassificationFailed::class]);
    $failures = 0;
    Event::listen(ClassificationFailed::class, function () use (&$failures) {
        $failures++;
    });
    $eventClass = $providerFails ? ClassificationFailed::class : ClassificationCompleted::class;
    Event::listen($eventClass, fn () => throw new LogicException('Listener failed'));
    if ($providerFails) {
        Classification::fake(fn () => throw new RuntimeException('Provider failed'));
    }
    try {
        runEventClassification($source);
        test()->fail('Expected the listener exception to propagate.');
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('Listener failed');
    }
    expect($failures)->toBe($providerFails ? 1 : 0);
})->with('classification sources')->with([false, true]);
