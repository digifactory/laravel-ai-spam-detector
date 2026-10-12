<?php

namespace DigiFactory\AiSpamDetector\Events;

final readonly class ClassificationCompleted
{
    public bool $wouldBlock;

    /**
     * @param  array<string, float>  $scores
     * @param  array<string, float>  $thresholds
     */
    public function __construct(
        public string $source,
        public string $provider,
        public string $model,
        public float $durationMs,
        public array $scores,
        public array $thresholds,
        public bool $blocked,
        ?bool $wouldBlock = null,
        public bool $observing = false,
    ) {
        $this->wouldBlock = $wouldBlock ?? $blocked;
    }
}
