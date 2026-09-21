<?php

namespace DigiFactory\AiSpamDetector\Rules;

use Closure;
use DigiFactory\AiSpamDetector\Classification\SpamQuestion;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Throwable;

class DoesNotClassifyAsSpam implements ValidationRule
{
    private readonly float $score;

    public function __construct(?float $score = null)
    {
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

        try {
            // Only this explicitly selected field is shared with the provider.
            $result = Classification::of(['body' => [$attribute => $value]])
                ->question('spam', SpamQuestion::make())
                ->timeout(config('ai-spam-detector.timeout', 5))
                ->classify(
                    provider: config('ai-spam-detector.provider', 'typesafe'),
                    model: config('ai-spam-detector.model', 'jev-latest'),
                );
            $answer = $result->answer('spam');
            $isSpam = $answer instanceof BooleanAnswer && $answer->isTrue($this->score);
        } catch (Throwable) {
            // Match the middleware: allow validation to continue on provider failure.
            return;
        }

        if ($isSpam) {
            $fail('ai-spam-detector::validation.spam')->translate();
        }
    }
}
