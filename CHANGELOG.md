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
- Three ADDITIVE request-schema rules from MOIC's real pricing model
  (2026-10-28). Every document the schemas accepted before still validates, and
  no stable code was added. **A host needs to do nothing** unless it wants the
  new rules.
  - `constraints.requiredWhen: {input, oneOf}` on every input type: required
    only when another input's supplied value is one of `oneOf`. An absent
    controller never triggers it. No `equals` sugar; the schema refuses it.
    Violations carry `rule: "requiredWhen"` and `when: {input, value}`.
  - `constraints.columnConstraints` on range and table inputs: per-column
    `required`, `integer`, `min`, `max`, `oneOf` and
    `oneOfColumn: {input?, column, where?: {column, oneOf}}`, keyed by a
    1-based index or a header text. Types stay in `columns`, ordering and
    uniqueness in `key`. Violations carry `rule: "column.*"`, `row`, `column`
    and `header`.
  - Array outputs: `outputs.<binding> = {type: "array", columns, maxRows?, currency?}`.
    The result is a `{t: "array"}` value with no `display`. P1 refuses an array
    output at `check()` with `FORMULA_TYPE`; P2 produces it. A scalar output
    that receives a multi-cell array gets a `#REF!` value (SPEC.md 9.3).
  - `StubEngine` returns one row of stub `#N/A` cells, one per declared
    column, for an array output.
- Violation order and caps for `FORMULA_INPUT_CONSTRAINT` refusals (SPEC.md
  8.2.3, agreed with MOIC and Prism). One total order on values: input binding
  by UTF-8 bytes (not declaration order, which JSON does not carry), then row,
  column, a fixed rule rank, header and canonical JSON. At most 100 violations
  per input and 500 per request. New refusal members `truncatedViolations`
  (input -> how many of its violations were not reported) and
  `truncatedByRequestCap` (how many the 500 cap cut); `violations` now has
  `maxItems: 500`. `FancyFormula\Violations::refusal()` is the reference
  implementation. **A host needs to do nothing** unless it relied on the order
  of `violations`, which was unspecified until now. The evaluation schema's
  own example was reordered to the canonical order.
