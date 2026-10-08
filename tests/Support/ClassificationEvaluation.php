<?php

namespace DigiFactory\AiSpamDetector\Tests\Support;

use DigiFactory\AiSpamDetector\Classification\PromptInjectionQuestion;
use DigiFactory\AiSpamDetector\Classification\SpamQuestion;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use RuntimeException;
use Throwable;

final class ClassificationEvaluation
{
    public function run(array $cases): array
    {
        $provider = config('ai-spam-detector.provider', 'typesafe');
        $model = config('ai-spam-detector.model', 'jev-latest');
        $thresholds = config('ai-spam-detector.thresholds');
        $questions = ['spam' => SpamQuestion::make(), 'prompt_injection' => PromptInjectionQuestion::make()];
        $rows = [];
        $metrics = [];
        $errors = 0;
        $mismatches = 0;

        foreach ($cases as $case) {
            $started = hrtime(true);
            $row = ['id' => $case['id'], 'locale' => $case['locale'], 'kind' => $case['kind'], 'expected' => $case['expected']];
            try {
                $response = Classification::of(['body' => $case['body'], 'headers' => []])
                    ->questions($questions)
                    ->timeout(config('ai-spam-detector.timeout', 5))
                    ->classify(provider: $provider, model: $model);
                $scores = [];
                foreach (['spam', 'prompt_injection'] as $category) {
                    $answer = $response->answer($category);
                    if (! $answer instanceof BooleanAnswer || ! is_finite($answer->probability)
                        || $answer->probability < 0 || $answer->probability > 1) {
                        throw new RuntimeException('Invalid classification probability.');
                    }
                    $scores[$category] = $answer->probability;
                }
                $row['scores'] = $scores;
                $row['predicted'] = [];
                $row['status'] = 'passed';
                foreach ($scores as $category => $score) {
                    $predicted = $score >= $thresholds[$category];
                    $row['predicted'][$category] = $predicted;
                    $expected = $case['expected'][$category];
                    $bucket = $expected === null ? 'unscored' : match (true) {
                        $expected && $predicted => 'true_positive',
                        ! $expected && ! $predicted => 'true_negative',
                        ! $expected && $predicted => 'false_positive',
                        default => 'false_negative',
                    };
                    foreach (['all', $case['locale']] as $locale) {
                        $metrics[$locale][$category] ??= array_fill_keys(['true_positive', 'true_negative', 'false_positive', 'false_negative', 'unscored'], 0);
                        $metrics[$locale][$category][$bucket]++;
                    }
                    if ($expected !== null && $expected !== $predicted) {
                        $row['status'] = 'mismatch';
                    }
                }
                if ($row['status'] === 'mismatch') {
                    $mismatches++;
                } elseif (in_array(null, $case['expected'], true)) {
                    $row['status'] = 'review';
                }
            } catch (Throwable $exception) {
                $errors++;
                $row['status'] = 'error';
                $row['exception_class'] = $exception::class;
            }
            $row['duration_ms'] = (hrtime(true) - $started) / 1_000_000;
            $rows[] = $row;
        }

        return [
            'generated_at' => gmdate(DATE_ATOM),
            'provider' => $provider,
            'model' => $model,
            'thresholds' => $thresholds,
            'criteria_sha256' => hash('sha256', json_encode(array_map(fn ($question) => $question->toArray(), $questions), JSON_THROW_ON_ERROR)),
            'fixtures_sha256' => hash('sha256', json_encode($cases, JSON_THROW_ON_ERROR)),
            'total' => count($cases),
            'errors' => $errors,
            'mismatches' => $mismatches,
            'metrics' => $metrics,
            'cases' => $rows,
        ];
    }
}
