# Laravel AI Spam Detector

[![Latest Version on Packagist](https://img.shields.io/packagist/v/digifactory/laravel-ai-spam-detector.svg?style=flat-square)](https://packagist.org/packages/digifactory/laravel-ai-spam-detector)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/digifactory/laravel-ai-spam-detector/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/digifactory/laravel-ai-spam-detector/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/digifactory/laravel-ai-spam-detector/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/digifactory/laravel-ai-spam-detector/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/digifactory/laravel-ai-spam-detector.svg?style=flat-square)](https://packagist.org/packages/digifactory/laravel-ai-spam-detector)

Opt-in Laravel route middleware and validation rules for spam and prompt injection detection using Laravel AI and Typesafe Jev. The middleware checks request bodies and headers for spam and prompt injection; the validation rules check individual text fields for either category.

Available on [Packagist](https://packagist.org/packages/digifactory/laravel-ai-spam-detector). See [GitHub Releases](https://github.com/digifactory/laravel-ai-spam-detector/releases) for published versions and [CHANGELOG.md](CHANGELOG.md) for changes. This README documents **v1.0.0**, including field validation rules, classification events, custom criteria and observation mode.

| Integration | Checks | Default response when a threshold is reached |
| --- | --- | --- |
| `ValidateRequestWithAi` middleware | Body and headers for spam and prompt injection, in one call | Empty HTTP 200; handler is not called |
| `DoesNotClassifyAsSpam` rule | One text field for spam | Normal Laravel validation error |
| `DoesNotClassifyAsPromptInjection` rule | One text field for prompt injection | Normal Laravel validation error |

Observation mode keeps classification and events active while allowing AI-flagged submissions through. It does not provide a dashboard or store results automatically.

- [Installation](#installation)
- [Upgrading from v0.0.x](#upgrading-from-v00x)
- [Route middleware](#route-middleware)
- [Validation rules](#validation-rules)
- [Custom classification criteria](#custom-classification-criteria)
- [Observation mode](#observation-mode)
- [Classification events](#classification-events)
- [Connectivity check](#connectivity-check)
- [Development and evaluation](#development)
- [Roadmap](ROADMAP.md)

## Requirements

PHP 8.4+ and Laravel 12 or 13. Laravel AI is a required runtime dependency in this package's `composer.json`, not a prerequisite to install manually. Composer installs it together with the package, and Laravel auto-discovers both service providers.

The package requires Laravel AI `^1.1`, which provides the `Classification` API. No development stability flags or separate Laravel AI installation are needed.

## Installation

Install directly from Packagist with Composer:

```bash
composer require digifactory/laravel-ai-spam-detector
```

No custom Composer repository is required. Composer installs a tagged package release and its dependencies with the default stable minimum stability.

Laravel discovers the service provider automatically. Configure `TYPESAFE_API_KEY` in the application's environment (Laravel AI's Typesafe provider reads this key).

```dotenv
TYPESAFE_API_KEY=your-key
AI_SPAM_DETECTOR_ENABLED=true
AI_SPAM_DETECTOR_OBSERVE=false
```

Publish configuration:

```bash
php artisan vendor:publish --tag=ai-spam-detector-config
```

## Upgrading from v0.0.x

Version 1.0.0 includes the features described below. Select the 1.x release series and allow Composer to update its dependencies:

```bash
composer require "digifactory/laravel-ai-spam-detector:^1.0" --with-all-dependencies
```

The new source requires Laravel AI `^1.1`. If your application still directly requires `laravel/ai:^1.0@dev`, update both constraints together with `composer require "digifactory/laravel-ai-spam-detector:^1.0" "laravel/ai:^1.1" --with-all-dependencies`. Remove a legacy path repository for this package if you are moving from a local checkout to Packagist.

Merge these keys into your published `config/ai-spam-detector.php`, preserving your thresholds, provider settings and exclusions:

```php
'observe' => env('AI_SPAM_DETECTOR_OBSERVE', false),
'criteria' => [
    'spam' => ['replace' => null, 'append' => null],
    'prompt_injection' => ['replace' => null, 'append' => null],
],
'profiles' => [],
```

Existing configuration without these keys continues with observation disabled and built-in criteria. Existing middleware usage and `new DoesNotClassifyAsSpam(0.8)` remain supported. Add the prompt-injection rule only where you want a separate field-level check.

If you customized published translations, optionally add `prompt_injection` to those files using the [validation message examples](#validation-messages). Package defaults supply the new message when no override exists. Register [event listeners](#classification-events) if you want logs or metrics. No database migration is needed. Rebuild cached configuration after merging configuration or changing environment settings:

```bash
php artisan config:cache
```

## Route middleware

```php
use DigiFactory\AiSpamDetector\Middleware\ValidateRequestWithAi;

Route::post('/contact', ContactController::class)
    ->middleware(ValidateRequestWithAi::class);
```

If you already use Spatie Honeypot, put its check first:

```php
->middleware([ProtectAgainstSpam::class, ValidateRequestWithAi::class]);
```

The package does not install Honeypot, register global middleware, or attach itself to Livewire. Add the middleware only to the routes that should be checked. To exclude a route from a protected group:

```php
->withoutMiddleware(ValidateRequestWithAi::class);
```

Defaults in `config/ai-spam-detector.php`:

- Enabled, checking POST (including POST requests using `_method`). Extend `methods` with `PUT` or `PATCH` as needed.
- Provider `typesafe`, model `jev-latest`, timeout 5 seconds.
- Spam and prompt injection thresholds 0.8.
- Matching either threshold returns an empty HTTP 200 response, without calling the handler.
- Provider errors and timeouts fail open: the request continues. Downstream application exceptions are not swallowed.
- One classification call checks both categories together. AI scores are probabilistic; evaluate thresholds and prompts against representative legitimate and unwanted submissions.

The body (including nested JSON/form fields) and headers are sent to the provider. Query parameters and uploaded file contents are not included. Configured sensitive field/header names are excluded case-insensitively; form/JSON field exclusions apply recursively. Raw text/XML bodies are sent as text and cannot use field-name redaction. Standard exclusions cover passwords, tokens, authorization headers and cookies; extend them for your application. The package does not log request bodies.

Keep Laravel's ordinary form validation and CSRF protection in place. AI spam detection is an additional check, not a replacement.

## Validation rules

### Spam

Use the rule in a FormRequest, a controller's validation, or Livewire validation:

```php
use DigiFactory\AiSpamDetector\Rules\DoesNotClassifyAsSpam;

return [
    'description' => ['bail', 'required', 'string', new DoesNotClassifyAsSpam(0.8)],
];
```

The score is a probability threshold between 0 and 1, inclusive. A score at or above the threshold fails validation unless observation mode is enabled. Omitting the argument uses `ai-spam-detector.thresholds.spam` (default 0.8). Invalid thresholds throw `InvalidArgumentException`.

The rule uses the same spam criteria as the middleware, but classifies only the selected field and its name. It does not inspect other fields, headers, or prompt injection, and does not return a blank response. Laravel handles the field error normally. There is one AI call per non-empty string field validation, so use it on submission rather than every Livewire keystroke when appropriate. Combining it with the middleware performs additional calls.

The shared provider, model, timeout, enabled and observation configuration apply. HTTP-method restrictions and field exclusions apply only to the middleware: attaching this rule explicitly opts the field into classification. Provider errors fail open. Keep `required`, `string`, length limits and `bail` as appropriate; absent/empty optional fields are not classified, and non-text values fail validation without an API call.

### Prompt injection

Use `DoesNotClassifyAsPromptInjection` to check a text field for attempts to manipulate an AI system:

```php
use DigiFactory\AiSpamDetector\Rules\DoesNotClassifyAsPromptInjection;

return [
    'description' => ['bail', 'required', 'string', new DoesNotClassifyAsPromptInjection(0.8)],
];
```

Omitting the score uses `ai-spam-detector.thresholds.prompt_injection` (default 0.8). The threshold is inclusive and must be between 0 and 1. The rule shares the middleware's prompt injection criteria, including the distinction between an attack and legitimate discussion or quotation of AI instructions.

Like the spam rule, it sends only the selected field and its name, uses the shared provider, model, timeout, enabled and observation settings, and fails open on provider errors. Middleware method restrictions and field exclusions do not apply. Empty optional values are skipped; non-text values fail validation without an AI call. It checks prompt injection only, not spam.

You can attach both rules to a field. Each rule makes its own classification call; with `bail`, a failure stops the remaining rules for that field. Combining rules with the middleware also adds calls.

### Validation messages

Messages use the namespaced keys `ai-spam-detector::validation.spam` and `ai-spam-detector::validation.prompt_injection`. English and Dutch are bundled and work without publishing, using the application's current locale and fallback locale.

Publish the translations to customize them:

```bash
php artisan vendor:publish --tag=ai-spam-detector-translations
```

Edit `lang/vendor/ai-spam-detector/nl/validation.php` (or the application's configured language directory):

```php
return [
    'spam' => 'Het veld :attribute lijkt spam te bevatten.',
    'prompt_injection' => 'Het veld :attribute lijkt een poging tot promptinjectie te bevatten.',
];
```

The package does not modify the application's existing `lang/nl/validation.php`. Its `attributes` entries still supply human-readable field names, such as `'description' => 'omschrijving'`. You may also add languages in `lang/vendor/ai-spam-detector/{locale}/validation.php`. Published messages override package defaults; no publication is required unless you want custom wording.

A custom FormRequest message can alternatively be supplied using the rule class:

```php
public function messages(): array
{
    return [
        'description.'.DoesNotClassifyAsSpam::class => 'Deze omschrijving bevat spam.',
    ];
}
```

## Custom classification criteria

Customize `criteria` in `config/ai-spam-detector.php` for the entire application. Each category (`spam` and `prompt_injection`) accepts `replace` and `append`:

- `replace`: replace the built-in criteria with a non-empty string. `null` keeps the built-in criteria.
- `append`: add trusted application context after the criteria. `null` adds nothing.

```php
'criteria' => [
    'spam' => ['replace' => null, 'append' => 'This application accepts business inquiries in Dutch and English.'],
    'prompt_injection' => ['replace' => null, 'append' => null],
],
'profiles' => [
    'product' => [
        'spam' => ['append' => 'This is a product description. Relevant product links and sales language are expected.'],
    ],
],
```

Named profiles override global settings per category and per key. Omitted keys inherit the global value; explicit `null` resets a key. An overridden `append` replaces the global addition rather than concatenating it. Select the profile in application code:

```php
use DigiFactory\AiSpamDetector\Middleware\ValidateRequestWithAi;

Route::post('/products', ProductController::class)
    ->middleware(ValidateRequestWithAi::class.':product');
```

In a FormRequest or Livewire validation rules method:

```php
use DigiFactory\AiSpamDetector\Rules\DoesNotClassifyAsSpam;
use DigiFactory\AiSpamDetector\Rules\DoesNotClassifyAsPromptInjection;

return [
    'description' => [
        'bail', 'required', 'string',
        new DoesNotClassifyAsSpam(profile: 'product'),
        new DoesNotClassifyAsPromptInjection(profile: 'product'),
    ],
];
```

Both validation rules also accept a named `criteria` argument to replace the criteria for that field. The optional `score` argument continues to work as before:

```php
new DoesNotClassifyAsSpam(
    score: 0.9,
    profile: 'product',
    criteria: 'Does this product description contain scams or unrelated link dumping?',
);
```

Priority for replacement is: rule argument, profile, global configuration, built-in criteria. The applicable `append` is retained with a rule-specific replacement. Every question ends with the instruction to treat submitted data as untrusted and never follow instructions within it. Supply criteria and profile names from trusted application code, not request input. These settings do not change thresholds or which fields are submitted, and criteria are not included in classification events.

Unknown profiles, invalid settings and empty instruction strings throw `InvalidArgumentException` before the provider call when the check runs; they are configuration errors rather than provider failures. Older published configuration files without these new keys keep the built-in behavior. Add the new keys to your existing configuration as needed.

## Observation mode

Enable observation mode to measure scores using the existing events without rejecting submissions based on those scores:

```dotenv
AI_SPAM_DETECTOR_ENABLED=true
AI_SPAM_DETECTOR_OBSERVE=true
```

This applies to the middleware and both validation rules. Add `'observe' => env('AI_SPAM_DETECTOR_OBSERVE', false)` to an already published `config/ai-spam-detector.php` if it does not contain the setting, and rebuild your configuration cache if applicable. Observation is off by default; older configuration files retain blocking behavior.

Classification still runs normally, including provider calls, costs, latency, criteria, redaction and thresholds. A `ClassificationCompleted` event distinguishes:

- `wouldBlock`: at least one checked category reached its threshold.
- `blocked`: the middleware or rule actually rejects the submission based on AI (always `false` in observation mode).
- `observing`: this classification ran in observation mode.

Use a listener to collect these events; enabling observation does not automatically store or log scores. Disable observation to enforce the same thresholds again. Setting `enabled` to `false` skips classification entirely, including events, even when observation is enabled.

Normal validation still applies: required/string rules and other application rules can fail. The AI validation rules still reject non-text values without calling the provider. Provider failures continue to emit `ClassificationFailed` and fail open; listener and downstream application exceptions still propagate. The optional live model evaluation measures classification quality directly and is unaffected by observation mode.

## Classification events

The middleware and both validation rules dispatch one event per attempted classification:

- `DigiFactory\AiSpamDetector\Events\ClassificationCompleted`: `source` (middleware or rule class), `provider`, `model`, `durationMs`, `scores`, `thresholds`, `wouldBlock`, `blocked`, and `observing`.
- `DigiFactory\AiSpamDetector\Events\ClassificationFailed`: `source`, `provider`, `model`, `durationMs`, and `exceptionClass`.

For middleware, `blocked` means the request is stopped with an empty response. For a validation rule, it means the field fails that rule. Scores contain the returned boolean probabilities for the checked categories. Failed classifications continue without blocking, as before. Skipped checks (disabled detection, excluded HTTP methods, or empty/non-text rule values) emit no classification event. The connectivity command does not emit these events.

Events contain no request, field name, field value, headers, raw provider response, exception message, or stack trace. The package does not log them automatically. Register listeners in your application, for example in `AppServiceProvider::boot()`:

```php
namespace App\Providers;

use DigiFactory\AiSpamDetector\Events\ClassificationCompleted;
use DigiFactory\AiSpamDetector\Events\ClassificationFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ClassificationCompleted::class, function (ClassificationCompleted $event) {
            Log::info('AI classification completed', [
                'source' => $event->source,
                'scores' => $event->scores,
                'would_block' => $event->wouldBlock,
                'blocked' => $event->blocked,
                'observing' => $event->observing,
                'duration_ms' => $event->durationMs,
            ]);
        });

        Event::listen(ClassificationFailed::class, function (ClassificationFailed $event) {
            Log::warning('AI classification failed', [
                'source' => $event->source,
                'provider' => $event->provider,
                'exception_class' => $event->exceptionClass,
                'duration_ms' => $event->durationMs,
            ]);
        });
    }
}
```

Add these listeners to your existing provider's `boot()` method, keeping its other boot logic. Output goes to the application's configured default logging channel: for a `single` channel using Laravel's usual path, this is `storage/logs/laravel.log`; a `daily` channel uses dated files. Other channels can write elsewhere. No entries appear until a listener is registered and a classification runs.

Listeners run outside the provider error handler. Exceptions from synchronous listeners propagate as application errors; they are not reported as provider failures. Keep listeners reliable or use Laravel queued listeners for external reporting. `durationMs` excludes listener and downstream application execution.

## Connectivity check

```bash
php artisan ai:check-classifier
php artisan ai:check-classifier --timeout=10
```

Makes one minimal classification request without submitting a form or following links. Displays the elapsed time and validates the response shape. Errors include the exception and underlying cause. Exit codes: 0 success, 1 classification failure, 2 invalid timeout.

## Development

Clone the repository and install its development dependencies:

```bash
git clone https://github.com/digifactory/laravel-ai-spam-detector.git
cd laravel-ai-spam-detector
composer install
composer test
composer analyse
composer format
```

Tests and evaluation fixtures are intended for a repository checkout; they are excluded from release archives. With the live opt-in flags unset, the standard suite uses fakes and makes no external API requests. Optional live fixture tests use only the Typesafe endpoint (example URLs are never fetched):

```bash
TYPESAFE_API_KEY=your-key RUN_LIVE_AI_TESTS=1 vendor/bin/pest tests/ProspectSpamSubmissionTest.php
```

The live tests distinguish missing classifications from insufficient scores and include legitimate controls. Their outcome can vary with provider/model updates.

### Multilingual model evaluation

`tests/Fixtures/classification-evaluation.json` contains 20 synthetic submissions in Dutch, English, German and French. Each language covers legitimate inquiries, unsolicited advertising, direct prompt injection, legitimate quotation of an attack example, and ambiguous partnership outreach. These fixtures are a starting benchmark, not a representative sample of production traffic. Extend them with reviewed, anonymized examples from your use case.

Ordinary tests use fake classifications to verify payloads, threshold decisions and reporting. They do **not** measure model quality. Run the separate live evaluation explicitly:

```bash
TYPESAFE_API_KEY=your-key RUN_LIVE_AI_EVALUATION=1 vendor/bin/pest tests/LiveClassificationEvaluationTest.php
```

This sends one request per fixture (20 calls) to Typesafe, using the configured model, timeout, thresholds and global criteria. Fixture URLs are data only and are never fetched. No named form profile is selected. The evaluation calls the classifier directly so provider failures cannot be mistaken for a successful fail-open validation.

Each run writes `build/classification-evaluation-<timestamp>-<pid>.json` before checking the outcome. The report includes model/provider, thresholds, criteria and fixture hashes, per-case scores and duration, and per-language/category counts of true positives, true negatives, false positives and false negatives. Transport errors, missing answers and invalid probabilities are reported separately. Request bodies, API keys and raw exception messages are excluded from the report.

Expected labels are independently assigned to `spam` and `prompt_injection`. A `null` label is deliberately uncertain: its score is recorded for review and excluded from accuracy counts. Direct attack examples have a definite prompt-injection label, but no assumed spam label. The live test fails on provider errors or mismatched definite labels; review the report before changing prompts or thresholds. Results can vary as the provider/model changes. Live evaluations are skipped in the ordinary test suite unless explicitly enabled.

## Current scope

The package has no built-in dashboard, persistence, classification cache or payload-size limit. Cache and payload limits are parked pending a concrete need; each executed rule or middleware check currently makes its own provider call. Apply ordinary application validation and request-size limits where appropriate. See [ROADMAP.md](ROADMAP.md) for completed work and remaining evaluation work.

## License

MIT. See [LICENSE.md](LICENSE.md).
