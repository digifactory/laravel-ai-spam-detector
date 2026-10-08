<?php

use DigiFactory\AiSpamDetector\Tests\Support\ClassificationEvaluation;
use Illuminate\Support\Facades\Http;

// One opt-in run evaluates all fixtures and saves the report before asserting quality.
test('evaluates multilingual fixtures against the actual Typesafe model', function () {
    if (getenv('RUN_LIVE_AI_EVALUATION') !== '1') {
        $this->markTestSkipped('Set RUN_LIVE_AI_EVALUATION=1 to run the multilingual model evaluation.');
    }

    $key = getenv('TYPESAFE_API_KEY');
    $this->assertNotEmpty($key, 'TYPESAFE_API_KEY is required for live evaluation.');
    config(['ai.providers.typesafe.key' => $key, 'ai-spam-detector.provider' => 'typesafe']);
    Http::preventStrayRequests();
    // URLs in fixtures remain inert data and are never fetched.
    Http::allowStrayRequests(['https://api.typesafe.ai/v1/systemone']);

    $cases = json_decode(file_get_contents(__DIR__.'/Fixtures/classification-evaluation.json'), true, flags: JSON_THROW_ON_ERROR);
    $report = (new ClassificationEvaluation)->run($cases);
    $directory = dirname(__DIR__).'/build';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $path = $directory.'/classification-evaluation-'.gmdate('Ymd-His').'-'.getmypid().'.json';
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);

    $diagnostic = 'Evaluation report: '.$path.'; provider errors: '.$report['errors'].'; mismatched cases: '.$report['mismatches'];
    $this->assertSame(0, $report['errors'], $diagnostic);
    $this->assertSame(0, $report['mismatches'], $diagnostic);
})->group('live-evaluation');
