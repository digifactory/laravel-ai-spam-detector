# Roadmap

Status reviewed on 8 October 2026. Implemented items below are included in v1.0.0. See [CHANGELOG.md](CHANGELOG.md) for release history and [README.md](README.md) for usage.

## Included in v1.0.0

- Prompt-injection validation rule alongside the existing spam rule.
- Classification result and failure events for middleware and both rules.
- Global criteria, named form profiles and per-rule criteria overrides.
- Observation mode that emits scores and decisions without enforcing AI blocks.
- A multilingual evaluation runner, reporting tests and twenty synthetic fixtures in Dutch, English, German and French.
- Tagged Laravel AI `^1.1` dependency.

## Evaluation still to do

- Run the opt-in live model evaluation and review its report. Offline tests verify implementation and reporting, not model accuracy.
- Add reviewed, anonymized production examples to make the corpus representative of actual usage.
- Review ambiguous examples manually; do not treat uncertain labels as definite ground truth.

## Parked

- Maximum classification payload size.
- Optional short-lived classification cache.

These were exploratory suggestions. There is no demonstrated need yet, so they are not scheduled. Reconsider them if large submissions or repeated identical classifications create measurable cost or latency. Define oversized-input behavior and cache invalidation before implementing either feature.

## Application integration

Observation mode needs an application event listener to make results visible through logs or metrics. The package provides events and documentation, not a dashboard or automatic storage.
