<?php

namespace DigiFactory\AiSpamDetector\Rules;

use Closure;
use DigiFactory\AiSpamDetector\Classification\SpamQuestion;
use DigiFactory\AiSpamDetector\Events\ClassificationCompleted;
use DigiFactory\AiSpamDetector\Events\ClassificationFailed;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Throwable;

class DoesNotClassifyAsSpam implements ValidationRule
{
    private readonly float $score;

    public function __construct(
        ?float $score = null,
        private readonly ?string $profile = null,
        private readonly ?string $criteria = null,
    ) {
        $score ??= (float) config('ai-spam-detector.thresholds.spam', 0.8);

        if (! is_finite($score) || $score < 0 || $score > 1) {
            throw new InvalidArgumentException('The spam score must be between 0 and 1.');
        }

        $this->score = $score;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('ai-spam-detector.enabled', true)) {
            return;
        }

        if (! is_string($value)) {
            $fail('validation.string')->translate();

            return;
        }

        if (trim($value) === '') {
            return;
        }

        $question = SpamQuestion::make($this->profile, $this->criteria);

        $provider = config('ai-spam-detector.provider', 'typesafe');
        $model = config('ai-spam-detector.model', 'jev-latest');
        $observing = (bool) config('ai-spam-detector.observe', false);
        $startedAt = hrtime(true);

        try {
            // Only this explicitly selected field is shared with the provider.
            $result = Classification::of(['body' => [$attribute => $value]])
                ->question('spam', $question)
                ->timeout(config('ai-spam-detector.timeout', 5))
                ->classify(
                    provider: $provider,
                    model: $model,
                );
            $answer = $result->answer('spam');
            $isSpam = $answer instanceof BooleanAnswer && $answer->isTrue($this->score);
            $event = new ClassificationCompleted(
                source: self::class,
                provider: $provider,
                model: $model,
                durationMs: (hrtime(true) - $startedAt) / 1_000_000,
                scores: $answer instanceof BooleanAnswer ? ['spam' => $answer->probability] : [],
                thresholds: ['spam' => $this->score],
                blocked: $isSpam && ! $observing,
                wouldBlock: $isSpam,
                observing: $observing,
            );
        } catch (Throwable $exception) {
            $event = new ClassificationFailed(
                source: self::class,
                provider: $provider,
                model: $model,
                durationMs: (hrtime(true) - $startedAt) / 1_000_000,
                exceptionClass: $exception::class,
            );
        }

        // Listener failures are application errors, not provider failures.
        event($event);

        if ($event instanceof ClassificationFailed) {
            return;
        }

        if ($event->blocked) {
            $fail('ai-spam-detector::validation.spam')->translate();
        }
    }
}
