# Laravel AI Spam Detector

Opt-in Laravel route middleware and a validation rule for spam detection using Laravel AI and Typesafe Jev. The middleware checks request bodies and headers for spam and prompt injection; the validation rule checks individual text fields for spam.

## Requirements

PHP 8.4+ and Laravel 12 or 13. Laravel AI is a required runtime dependency in this package's `composer.json`, not a prerequisite to install manually. Composer installs it together with the package, and Laravel auto-discovers both service providers.

The current classification implementation uses Laravel AI `1.x-dev`. The installation command below explicitly allows that development dependency in the consuming application: Composer stability flags are root-only. The additional `laravel/ai` argument is a stability/version constraint, not a separate installation step. Once using a stable release with the `Classification` API, this extra root constraint is no longer necessary.

## Installation

Install directly from Packagist with Composer:

```bash
composer require digifactory/laravel-ai-spam-detector "laravel/ai:^1.0@dev"
```

No custom Composer repository is required. Composer installs a tagged package release. The explicit `laravel/ai` constraint allows its development version while keeping the application's `minimum-stability` unchanged.

Laravel discovers the service provider automatically. Configure `TYPESAFE_API_KEY` in the application's environment (Laravel AI's Typesafe provider reads this key).

```dotenv
TYPESAFE_API_KEY=your-key
AI_SPAM_DETECTOR_ENABLED=true
```

Publish configuration:

```bash
php artisan vendor:publish --tag=ai-spam-detector-config
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

## Validation rule

Use the rule in a FormRequest, a controller's validation, or Livewire validation:

```php
use DigiFactory\AiSpamDetector\Rules\DoesNotClassifyAsSpam;

return [
    'description' => ['bail', 'required', 'string', new DoesNotClassifyAsSpam(0.8)],
];
```

The score is a probability threshold between 0 and 1, inclusive. A score at or above the threshold fails validation. Omitting the argument uses `ai-spam-detector.thresholds.spam` (default 0.8). Invalid thresholds throw `InvalidArgumentException`.

The rule uses the same spam criteria as the middleware, but classifies only the selected field and its name. It does not inspect other fields, headers, or prompt injection, and does not return a blank response. Laravel handles the field error normally. There is one AI call per non-empty string field validation, so use it on submission rather than every Livewire keystroke when appropriate. Combining it with the middleware performs additional calls.

The shared provider, model, timeout and enabled configuration apply. HTTP-method restrictions and field exclusions apply only to the middleware: attaching this rule explicitly opts the field into classification. Provider errors fail open. Keep `required`, `string`, length limits and `bail` as appropriate; absent/empty optional fields are not classified, and non-text values fail validation without an API call.

Messages use the namespaced key `ai-spam-detector::validation.spam`. English and Dutch are bundled and work without publishing, using the application's current locale and fallback locale.

Publish the translations to customize them:

```bash
php artisan vendor:publish --tag=ai-spam-detector-translations
```

Edit `lang/vendor/ai-spam-detector/nl/validation.php` (or the application's configured language directory):

```php
return [
    'spam' => 'Het veld :attribute lijkt spam te bevatten.',
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

The standard suite uses fakes and makes no external API requests. Optional live fixture tests use only the Typesafe endpoint (example URLs are never fetched):

```bash
TYPESAFE_API_KEY=your-key RUN_LIVE_AI_TESTS=1 vendor/bin/pest tests/ProspectSpamSubmissionTest.php
```

The live tests distinguish missing classifications from insufficient scores and include legitimate controls. Their outcome can vary with provider/model updates.

## License

MIT. See [LICENSE.md](LICENSE.md).
