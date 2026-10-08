# Changelog

## v1.0.0 - 2026-10-08

### Added

- `DoesNotClassifyAsPromptInjection` validation rule with a configurable threshold and English and Dutch messages, sharing the middleware's prompt-injection criteria.
- `ClassificationCompleted` and `ClassificationFailed` events for middleware and both rules. Events expose classification metadata without request bodies, headers or raw exception messages.
- Global criteria replacement and extension, named profiles for routes and rules, and per-rule criteria overrides.
- Opt-in observation mode via `AI_SPAM_DETECTOR_OBSERVE`. Result events distinguish `wouldBlock`, `blocked` and `observing`.
- Twenty synthetic evaluation fixtures in Dutch, English, German and French, offline reporting tests, and an opt-in live Typesafe evaluation that writes JSON reports. Uncertain labels are recorded separately from definite expectations.
- Upgrade instructions, event logging examples and a maintained roadmap.

### Changed

- Require tagged Laravel AI releases through `^1.1`; installation no longer needs a development stability constraint.
- Share prompt-injection question construction between middleware and the new rule.
- Surface invalid criteria/profile configuration before provider calls. Provider failures still fail open; application listener exceptions propagate.

### Fixed

- Make the missing-answer regression test deterministic by supplying an explicit classification response instead of allowing the AI fake to generate missing answers.

## v0.0.2 - 2026-09-21

- Update README and installation documentation.
- Add and update GitHub Actions for tests, static analysis, code style, changelog updates and dependency maintenance.

[Full changelog](https://github.com/digifactory/laravel-ai-spam-detector/compare/v0.0.1...v0.0.2)

## v0.0.1 - 2026-09-21

- Initial release of opt-in route middleware for spam and prompt-injection detection with Laravel AI and Typesafe Jev.
- `DoesNotClassifyAsSpam` field validation rule with a configurable threshold and shared middleware spam criteria.
- Publishable configuration and English/Dutch validation translations.
- `ai:check-classifier` connectivity command, automated tests and optional live spam fixtures.
