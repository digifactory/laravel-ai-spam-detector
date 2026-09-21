<?php

use DigiFactory\AiSpamDetector\Middleware\ValidateRequestWithAi;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

test('classifies form submissions before the form handler', function (bool $live, bool $spam, array $overrides = []) {
    if ($live && getenv('RUN_LIVE_AI_TESTS') !== '1') {
        $this->markTestSkipped('Set RUN_LIVE_AI_TESTS=1 to test actual Jev spam detection.');
    }

    config(['ai-spam-detector.enabled' => true]);

    // The URLs are inert form values. Never fetch them, including in the live test.
    Http::preventStrayRequests();

    if ($live) {
        config(['ai.providers.typesafe.key' => getenv('TYPESAFE_API_KEY') ?: null]);
        expect(config('ai.providers.typesafe.key'))->not->toBeEmpty();
        Http::allowStrayRequests(['https://api.typesafe.ai/v1/systemone']);
    } else {
        // This checks middleware behavior, not the model's ability to recognize spam.
        Classification::fake([[
            'spam' => new BooleanAnswer($spam ? 0.99 : 0.01),
            'prompt_injection' => new BooleanAnswer(0.01),
        ]])->preventStrayClassifications();
    }

    $url = 'https://example.com/promotion';
    // Fixed fictional data keeps regression and optional live tests reproducible.
    $data = [
        '_token' => 'test-csrf-token',
        'company' => $url,
        'company_type' => 'organisation',
        'first_name' => $url,
        'last_name' => $url,
        'email_verify' => 'john.doe@example.com',
        'email' => '',
        'phone' => '0612345678',
        'lead' => $url,
    ];
    $request = Request::create('/contact', 'POST', $data, [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'text/html',
    ]);

    if (! $spam) {
        $data['company'] = 'Example Company';
        $data['first_name'] = 'John';
        $data['last_name'] = 'Doe';
        $data['email_verify'] = 'john.doe@example.org';
        // Retain the same referral URL to ensure a link alone is not blocked.
        $request->request->replace($data);
    }

    $data = array_replace($data, $overrides);
    $request->request->replace($data);

    $handlerCalled = false;
    $classification = null;
    $httpStatus = null;

    Event::listen(Classified::class, function (Classified $event) use (&$classification) {
        $classification = $event->response;
    });

    Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$httpStatus) {
        $httpStatus = $event->response->status();
    });

    $response = app(ValidateRequestWithAi::class)->handle($request, function () use (&$handlerCalled) {
        $handlerCalled = true;

        return response('form handler reached');
    });

    $diagnostic = $classification === null
        ? 'No classification completed; middleware failed open. HTTP status: '.($httpStatus ?? 'no response').'.'
        : 'Jev answers: '.json_encode($classification->answers).'; thresholds: '.json_encode(config('ai-spam-detector.thresholds'));

    $this->assertNotNull($classification, $diagnostic);
    $this->assertSame($spam ? '' : 'form handler reached', $response->getContent(), $diagnostic);
    expect($response->getStatusCode())->toBe(200)
        ->and($handlerCalled)->toBe(! $spam);

    if (! $live) {
        unset($data['_token']);
        Classification::assertClassified(fn (ClassificationPrompt $prompt) => $prompt->state['body'] === $data
            && $prompt->state['headers']['content-type'] === ['application/x-www-form-urlencoded']
            && $prompt->asks('spam')
            && $prompt->asks('prompt_injection')
        );
        Http::assertNothingSent();
    }
})->with([
    'spam regression with fake classification' => [false, true],
    'legitimate regression with fake classification' => [false, false],
    'live Jev spam classification' => [true, true],
    'live Jev legitimate submission with referral link' => [true, false],
    'promotional contact fields regression' => [false, true, [
        'company' => 'https://example.com/special-offers',
        'company_type' => 'company',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email_verify' => 'offers@example.com',
        'phone' => 'BUY NOW! Limited-time discount!',
        'lead' => 'Buy our products now! Visit our store for exclusive discounts!',
        'email' => null,
    ]],
    'live Jev promotional contact fields' => [true, true, [
        'company' => 'https://example.com/special-offers',
        'company_type' => 'company',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email_verify' => 'offers@example.com',
        'phone' => 'BUY NOW! Limited-time discount!',
        'lead' => 'Buy our products now! Visit our store for exclusive discounts!',
        'email' => null,
    ]],
    'live Jev legitimate business inquiry' => [true, false, [
        'company' => 'Example Company',
        'company_type' => 'company',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email_verify' => 'john.doe@example.com',
        'phone' => '0612345678',
        'lead' => 'Found you through a search engine. We would like a website for our company with information about our products.',
    ]],
]);
