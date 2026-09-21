<?php

use DigiFactory\AiSpamDetector\Rules\DoesNotClassifyAsSpam;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function () {
    Classification::fake([['spam' => new BooleanAnswer(0.8)]])->preventStrayClassifications();
});

test('fails on the threshold and attaches a normal validation error to the field', function () {
    $validator = Validator::make(['description' => 'Buy now'], ['description' => [new DoesNotClassifyAsSpam(0.8)]]);
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('description'))->toBe('The description field appears to contain spam.');
});

test('allows a score below the specified threshold', function () {
    expect(Validator::make(['description' => 'Hello'], ['description' => [new DoesNotClassifyAsSpam(0.9)]])->passes())->toBeTrue();
});

test('uses the configured default and classifies only the selected field', function () {
    config(['ai-spam-detector.thresholds.spam' => 0.7]);
    $validator = Validator::make(['description' => 'Hello', 'password' => 'secret'], ['description' => [new DoesNotClassifyAsSpam]]);
    expect($validator->fails())->toBeTrue();
    Classification::assertClassified(fn (ClassificationPrompt $prompt) => $prompt->state === ['body' => ['description' => 'Hello']]
        && count($prompt->questions) === 1
        && $prompt->asks('spam')
        && $prompt->provider->name() === 'typesafe'
        && $prompt->model === 'jev-latest'
        && $prompt->timeout === 5
    );
});

test('allows provider failures without hiding validation errors from other rules', function () {
    Classification::fake(fn () => throw new RuntimeException('Timeout'));
    $validator = Validator::make(['description' => 'Hello'], [
        'description' => [new DoesNotClassifyAsSpam],
        'name' => ['required'],
    ]);
    expect($validator->errors()->has('description'))->toBeFalse()
        ->and($validator->errors()->has('name'))->toBeTrue();
});

test('supports disabling classification', function () {
    config(['ai-spam-detector.enabled' => false]);
    expect(Validator::make(['description' => 'Buy now'], ['description' => [new DoesNotClassifyAsSpam]])->passes())->toBeTrue();
    Classification::assertNothingClassified();
});

test('does not classify absent or empty optional values', function () {
    foreach ([[], ['description' => ''], ['description' => null]] as $data) {
        expect(Validator::make($data, ['description' => ['nullable', new DoesNotClassifyAsSpam]])->passes())->toBeTrue();
    }
    Classification::assertNothingClassified();
});

test('rejects non-text values without sending them to AI', function () {
    expect(Validator::make(['description' => ['nested' => 'text']], ['description' => [new DoesNotClassifyAsSpam]])->fails())->toBeTrue();
    Classification::assertNothingClassified();
});

test('supports custom error messages', function () {
    $validator = Validator::make(['description' => 'Buy now'], ['description' => [new DoesNotClassifyAsSpam]], [
        'description.'.DoesNotClassifyAsSpam::class => 'Spam is niet toegestaan.',
    ]);
    expect($validator->errors()->first('description'))->toBe('Spam is niet toegestaan.');
});

test('rejects invalid score configuration', function (float $score) {
    new DoesNotClassifyAsSpam($score);
})->with([-0.1, 1.1, INF, NAN])->throws(InvalidArgumentException::class);
