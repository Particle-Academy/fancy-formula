# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Pre-1.0, breaking changes land in MINOR releases.** The version number is not
promising otherwise until 1.0. The JSON Schemas carry their own spec version
(`"spec": "1"`); a change to them is called out here as BREAKING with what a
consumer must do.

## [Unreleased]

Nothing is released yet. This is phase P0: the contract and the oracle, with no
evaluator. **There is nothing to install or upgrade.**

### Added

- `SPEC.md`, spec version 1: grammar, value model, coercion, floating-point
  policy, dates, input types and constraints, results, stable codes, trace,
  security and limits. Every Google Sheets behaviour not yet verified is marked
  as a hypothesis (H-01 to H-35) and names the capture rows that decide it.
- `schema/request.schema.json`, `check-report.schema.json`,
  `evaluation.schema.json` and `trace.schema.json`, each with examples that the
  test suite validates. These are the contract Prism's doMath builds against.
  The request schema refuses a clock, seed or provider, and any limit above its
  hard ceiling.
- `FancyFormula\FormulaError` with the stable codes, held by a test to the same
  sets as the schemas and SPEC.md.
- `FancyFormula\StubEngine`: schema-valid `check()` / `evaluate()` output that
  computes nothing (every result is `#N/A` with a "stub" detail, engine
  `fancy-formula 0.0.0-stub`), plus `StubEngine::example()` for the schemas'
  refusal and abort shapes.
- The capture workbook v1 (`capture/v1/capture-v1.xlsx`, 689 rows, every
  formula written with no cached value) and its `request.json`, built by
  `scripts/build-capture-workbook.php` with holy-sheet. Not yet captured.
- Dev dependency `particle-academy/holy-sheet` (first party) for the capture
  generator. No runtime dependencies.
