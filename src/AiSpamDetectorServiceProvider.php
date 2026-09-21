<?php

namespace DigiFactory\AiSpamDetector;

use DigiFactory\AiSpamDetector\Commands\CheckAiClassifier;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AiSpamDetectorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-ai-spam-detector')
            ->hasConfigFile('ai-spam-detector')
            ->hasTranslations()
            ->hasCommand(CheckAiClassifier::class);
    }
}
