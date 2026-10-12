<?php

namespace DigiFactory\AiSpamDetector\Middleware;

use Closure;
use DigiFactory\AiSpamDetector\Classification\PromptInjectionQuestion;
use DigiFactory\AiSpamDetector\Classification\SpamQuestion;
use DigiFactory\AiSpamDetector\Events\ClassificationCompleted;
use DigiFactory\AiSpamDetector\Events\ClassificationFailed;
use Illuminate\Http\Request;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ValidateRequestWithAi
{
    public function handle(Request $request, Closure $next, ?string $profile = null): Response
    {
        $config = config('ai-spam-detector');

        // Include POST requests that use Laravel's _method override as well.
        if (! $config['enabled'] || (! in_array($request->getRealMethod(), $config['methods'], true)
            && ! in_array($request->method(), $config['methods'], true))) {
            return $next($request);
        }

        $questions = [
            'spam' => SpamQuestion::make($profile),
            'prompt_injection' => PromptInjectionQuestion::make($profile),
        ];
        $observing = (bool) ($config['observe'] ?? false);
        $startedAt = hrtime(true);

        try {
            // Read the body directly: input()/all() also include query parameters/files.
            $body = $request->isJson() ? $request->json()->all() : $request->request->all();
            $body = $this->withoutExcludedKeys($body, $config['except_fields']);

            // Preserve non-form bodies, such as text/plain and XML.
            if (! $request->isJson() && $body === [] && $request->request->count() === 0
                && ! str_contains(strtolower($request->header('Content-Type', '')), 'multipart/form-data')) {
                $body = $request->getContent();
            }

            $result = Classification::of([
                'body' => $body,
                'headers' => $this->withoutExcludedKeys($request->headers->all(), $config['except_headers']),
            ])->questions($questions)->timeout($config['timeout'])->classify(provider: $config['provider'], model: $config['model']);

            $scores = [];
            foreach (['spam', 'prompt_injection'] as $key) {
                $answer = $result->answers[$key] ?? null;
                if ($answer instanceof BooleanAnswer) {
                    $scores[$key] = $answer->probability;
                }
            }

            $wouldBlock = false;
            foreach ($config['thresholds'] as $key => $threshold) {
                $answer = $result->answer($key);

                if ($answer instanceof BooleanAnswer && $answer->isTrue($threshold)) {
                    $wouldBlock = true;

                    break;
                }
            }
            $event = new ClassificationCompleted(
                source: self::class,
                provider: $config['provider'],
                model: $config['model'],
                durationMs: (hrtime(true) - $startedAt) / 1_000_000,
                scores: $scores,
                thresholds: $config['thresholds'],
                blocked: $wouldBlock && ! $observing,
                wouldBlock: $wouldBlock,
                observing: $observing,
            );
        } catch (Throwable $exception) {
            $event = new ClassificationFailed(
                source: self::class,
                provider: $config['provider'],
                model: $config['model'],
                durationMs: (hrtime(true) - $startedAt) / 1_000_000,
                exceptionClass: $exception::class,
            );
        }

        // Dispatch outside the provider catch so listener errors cannot bypass a block.
        event($event);

        if ($event instanceof ClassificationCompleted && $event->blocked) {
            return response('');
        }

        // Keep downstream application exceptions outside the fail-open catch.
        return $next($request);
    }

    private function withoutExcludedKeys(array $values, array $excluded): array
    {
        $excluded = array_map('strtolower', $excluded);

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $excluded, true)) {
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = $this->withoutExcludedKeys($value, $excluded);
            }
        }

        return $values;
    }
}
