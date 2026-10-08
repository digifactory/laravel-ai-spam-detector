<?php

namespace DigiFactory\AiSpamDetector\Events;

final readonly class ClassificationFailed
{
    public function __construct(
        public string $source,
        public string $provider,
        public string $model,
        public float $durationMs,
        public string $exceptionClass,
    ) {}
}
