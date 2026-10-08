<?php

use DigiFactory\AiSpamDetector\Tests\Support\ClassificationEvaluation;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

function evaluationFixtures(): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/classification-evaluation.json'), true, flags: JSON_THROW_ON_ERROR);
}

test('keeps a balanced multilingual corpus with explicit uncertain labels', function () {
    $cases = evaluationFixtures();
    expect(count(array_unique(array_column($cases, 'id'))))->toBe(count($cases));
    foreach (['nl', 'en', 'de', 'fr'] as $locale) {
        $localized = array_values(array_filter($cases, fn ($case) => $case['locale'] === $locale));
        expect(array_column($localized, 'kind'))->toBe(['legitimate', 'spam', 'injection', 'quotation', 'borderline']);
        foreach ($localized as $case) {
            expect($case['body']['message'])->toBeString()->not->toBeEmpty();
            foreach (['spam', 'prompt_injection'] as $category) {
                expect(in_array($case['expected'][$category], [true, false, null], true))->toBeTrue();
            }
        }
    }
});

test('evaluates reporting with fakes without claiming model accuracy', function () {
    Http::preventStrayRequests();
    $cases = evaluationFixtures();
    $answers = array_map(fn ($case) => [
        'spam' => new BooleanAnswer($case['expected']['spam'] === true ? 0.9 : 0.1),
        'prompt_injection' => new BooleanAnswer($case['expected']['prompt_injection'] === true ? 0.9 : 0.1),
    ], $cases);
    Classification::fake($answers)->preventStrayClassifications();
    $report = (new ClassificationEvaluation)->run($cases);
    expect($report['total'])->toBe(20)
        ->and($report['errors'])->toBe(0)
        ->and($report['mismatches'])->toBe(0)
        ->and($report['metrics']['all']['spam']['true_positive'])->toBe(4)
        ->and($report['metrics']['all']['spam']['true_negative'])->toBe(8)
        ->and($report['metrics']['all']['spam']['unscored'])->toBe(8)
        ->and($report['metrics']['all']['prompt_injection']['true_positive'])->toBe(4)
        ->and($report['metrics']['all']['prompt_injection']['unscored'])->toBe(4);
    foreach (['nl', 'en', 'de', 'fr'] as $locale) {
        expect($report['metrics'][$locale]['spam']['true_positive'])->toBe(1);
    }
    Classification::assertClassified(fn (ClassificationPrompt $prompt) => $prompt->state === ['body' => $cases[0]['body'], 'headers' => []]
        && $prompt->asks('spam') && $prompt->asks('prompt_injection')
    );
    Http::assertNothingSent();
});

test('separates incorrect predictions uncertain examples and provider failures', function () {
    $cases = evaluationFixtures();
    $selected = [$cases[0], $cases[1], $cases[4], $cases[2]];
    $call = 0;
    Classification::fake(function () use (&$call) {
        $call++;
        if ($call === 4) {
            throw new RuntimeException('Secret provider diagnostic');
        }

        return ['spam' => new BooleanAnswer($call === 2 ? 0.1 : 0.8), 'prompt_injection' => new BooleanAnswer(0.1)];
    })->preventStrayClassifications();
    $report = (new ClassificationEvaluation)->run($selected);
    expect(array_column($report['cases'], 'status'))->toBe(['mismatch', 'mismatch', 'review', 'error'])
        ->and($report['errors'])->toBe(1)
        ->and($report['mismatches'])->toBe(2)
        ->and($report['metrics']['all']['spam']['false_positive'])->toBe(1)
        ->and($report['metrics']['all']['spam']['false_negative'])->toBe(1)
        ->and($report['metrics']['all']['spam']['unscored'])->toBe(1)
        ->and(json_encode($report))->not->toContain('Secret provider diagnostic');
});

test('counts missing or invalid probabilities as errors rather than successful evaluations', function (array $answers) {
    Classification::fake([new ClassificationResponse($answers, new TextUsage, new Meta)])->preventStrayClassifications();
    $report = (new ClassificationEvaluation)->run([evaluationFixtures()[0]]);
    expect($report['errors'])->toBe(1)->and($report['metrics'])->toBe([]);
})->with([
    [[]],
    [['spam' => new BooleanAnswer(0.1)]],
    [['spam' => new BooleanAnswer(NAN), 'prompt_injection' => new BooleanAnswer(0.1)]],
    [['spam' => new BooleanAnswer(1.1), 'prompt_injection' => new BooleanAnswer(0.1)]],
]);
