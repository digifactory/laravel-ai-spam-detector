<?php

namespace DigiFactory\AiSpamDetector\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use RuntimeException;
use Throwable;

class CheckAiClassifier extends Command
{
    protected $signature = 'ai:check-classifier {--timeout= : Timeout in seconds (defaults to the middleware timeout)}';

    protected $description = 'Check Typesafe/Jev with one minimal Laravel AI classification request';

    public function handle(): int
    {
        $timeout = filter_var($this->option('timeout') ?? config('ai-spam-detector.timeout', 5), FILTER_VALIDATE_INT);

        if ($timeout === false || $timeout < 1) {
            $this->error('Timeout must be a positive number of seconds.');

            return self::INVALID;
        }

        $provider = config('ai-spam-detector.provider', 'typesafe');
        $model = config('ai-spam-detector.model', 'jev-latest');
        $this->info("Checking {$provider}/{$model} (timeout: {$timeout}s)...");
        $started = microtime(true);

        try {
            $result = Classification::of('The sky is blue.')
                ->question('health', new Boolean('Does the text mention the sky?'))
                ->timeout($timeout)
                ->classify(provider: $provider, model: $model);

            $answer = $result->answer('health');

            // Check the response shape, not the model's classification accuracy.
            if (! $answer instanceof BooleanAnswer || ! is_finite($answer->probability)
                || $answer->probability < 0 || $answer->probability > 1) {
                throw new RuntimeException('The classifier returned an invalid probability.');
            }
        } catch (Throwable $exception) {
            $this->error('Classifier check failed after '.round(microtime(true) - $started, 2).'s.');
            $this->error($exception::class.': '.$exception->getMessage());

            if ($cause = $exception->getPrevious()) {
                $this->error('Cause: '.$cause->getMessage());
            }

            return self::FAILURE;
        }

        $this->info('Classifier is responding: valid answer received in '.round(microtime(true) - $started, 2).'s.');

        return self::SUCCESS;
    }
}
