<?php

use DigiFactory\AiSpamDetector\AiSpamDetectorServiceProvider;
use DigiFactory\AiSpamDetector\Rules\DoesNotClassifyAsSpam;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function () {
    Classification::fake([['spam' => new BooleanAnswer(0.99)]]);
});

test('uses Dutch translations and application attribute names without publishing', function () {
    app()->setLocale('nl');
    app('translator')->addLines(['validation.attributes.description' => 'omschrijving'], 'nl');
    $validator = Validator::make(['description' => 'spam'], ['description' => [new DoesNotClassifyAsSpam]]);
    expect($validator->errors()->first('description'))->toBe('Het veld omschrijving lijkt spam te bevatten.');
});

test('falls back to English for an unsupported locale', function () {
    app()->setLocale('zz');
    app('translator')->setFallback('en');
    $validator = Validator::make(['description' => 'spam'], ['description' => [new DoesNotClassifyAsSpam]]);
    expect($validator->errors()->first('description'))->toBe('The description field appears to contain spam.');
});

test('publishes translations and respects an application override', function () {
    $paths = ServiceProvider::pathsToPublish(AiSpamDetectorServiceProvider::class, 'ai-spam-detector-translations');
    expect(array_values($paths))->toContain(lang_path('vendor/ai-spam-detector'));
    $target = lang_path('vendor/ai-spam-detector/nl/validation.php');
    $original = is_file($target) ? file_get_contents($target) : null;

    try {
        $this->artisan('vendor:publish', ['--tag' => 'ai-spam-detector-translations'])->assertSuccessful();
        expect(is_file($target))->toBeTrue();
        file_put_contents($target, "<?php return ['spam' => 'Geen spam in :attribute toegestaan.'];");
        app()->setLocale('nl');
        $validator = Validator::make(['description' => 'spam'], ['description' => [new DoesNotClassifyAsSpam]]);
        expect($validator->errors()->first('description'))->toBe('Geen spam in description toegestaan.');
    } finally {
        if ($original !== null) {
            file_put_contents($target, $original);
        } elseif (is_file($target)) {
            unlink($target);
        }
    }
});
