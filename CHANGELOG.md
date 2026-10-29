# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Pre-1.0, breaking changes land in MINOR releases.** The version number is not
promising otherwise until 1.0. The JSON Schemas carry their own spec version
(`"spec": "1"`); a change to them is called out here as BREAKING with what a
consumer must do.

## [Unreleased]

P1 slice 1: the first real evaluator, for the pricing set. Not yet released:
0.1.0 is tagged only after the Google Sheets goldens pass.

### Added

- `Formula::parse($source, $maxBytes = 8192, $maxDepth = 64)` with
  `->normalized()`, `->ast()`, `->references()` and `->spans()`. The full
  grammar of SPEC.md section 3: references with and without sheets, quoted
  sheets, open ranges (`B:B`, `1:1`), array literals (references allowed
  inside, H-21), empty arguments (`INDEX(r,,1)`), postfix `%` once, and LET,
  LAMBDA and an immediate LAMBDA invocation as syntax nodes. Malformed input
  throws `FormulaError` with `FORMULA_SYNTAX`, `FORMULA_TOO_LONG` (UTF-8 bytes,
  before lexing) or `FORMULA_TOO_DEEP` (fancy-expr's depth definition, checked
  on the way down and up) and a byte offset.
- Confirmed by the partial first capture and pinned: unary minus binds before
  `^` (`=-2^0.5` is `#NUM!`), and `=10%%` is `FORMULA_SYNTAX`.
- `#NULL!` and `#ERROR!` are error literals (the capture decided H-16).
- **`FancyFormula\Engine`: the first real evaluator** (SPEC.md section 15 lists
  slice 1). `check()` returns a `CheckReport`, `evaluate()` an `Evaluation`;
  both have `toArray()`, and `json_encode()` of either is the schema-valid JSON.
  `Engine::ENGINE` is `fancy-formula 0.1.0`.
  - Typed, value-only inputs bound to names or cells; constraint validation of
    the supplied values BEFORE any formula runs, in the canonical order with
    the caps; topological evaluation with lazy IF, IFS, SWITCH, IFERROR, IFNA,
    LET and CHOOSE; the step, range, text, cell and wall-clock budgets with
    typed aborts; the float policy (one comparator, 15-digit decimal rounding,
    half away from zero); date serials; traces at `none`, `lookups` and `full`
    with the lookup `bracket` and `probes`; the fingerprint;
    `Engine::functions()`.
  - check() diagnostics: unknown function (naming a later slice when it is
    one) or name, circular references with the cycle path, arity, type,
    refused, needs clock, needs seed, input invalid, dynamic reference, and
    the declared shape of every constraint.
- **Host construction, the contract Prism codes against** (SPEC.md 1.1):
  `new Engine(new Options(limits:, clock:, random:, providers:, functions:))`,
  every argument optional. New `Clock` interface with `FixedClock`, `Seed`,
  `FunctionPolicy` (`all()`, `only()`, `->deny()`), `ExternalProvider` with
  `ProviderContext`, `CheckReport`, `Evaluation`. Provider calls are recorded in
  the trace's `external` list and folded into the fingerprint.
- `StubEngine` is kept, unchanged, for hosts built against 0.0.1.
- **The Google Sheets goldens run in CI.** Dev dependency
  `particle-academy/fancy-conformance` pinned EXACTLY at `0.39.0` (first party),
  asserted by a test as in fancy-expr. All 13 `formula/*` suites run: 482 rows
  in slice 1 pass; 205 are skipped, each with a reason naming slice 2, decided
  from the formula text and never from the engine's output. Four rows whose
  golden is lossier than its declared tolerance (two 10-digit values, two tiny
  values exported as 0) are still asserted, at the precision the export kept.

### Changed

- **SPEC.md section 13 records the capture's verdict on every hypothesis**:
  CONFIRMED, REFUTED, or OPEN (only where its rows need a slice-2 function).
  Refuted, and the engine now follows Sheets: `^` is right-associative
  (`=2^3^2` is 512); there is no near-zero snap in `+` and `-`; INDEX and
  CHOOSE out of range are `#NUM!` (MOIC's case F is `#NUM!`, not `#REF!`); an
  omitted IFERROR fallback is EMPTY; open ranges span the 1000 x 26 grid;
  numbers from 1E+15 convert to text in full decimal, truncated past the 15th
  digit; approximate lookups order raw doubles. SPEC.md section 2 names the two
  deliberate divergences: a Sheets `#ERROR!` parse error is our
  `FORMULA_SYNTAX`, a Sheets `#N/A` wrong argument count our `FORMULA_ARITY`.

### Fixed

- The trace schema's VLOOKUP and SUMIF examples had a `span` ending one byte
  past the end of their own formula (`[1, 37]` on a 36-byte formula). They are
  now `[1, 36]` and `[1, 29]`, and a test holds every example's spans inside
  its formula. Examples only; no shape changed.

## [0.0.1] - 2026-10-29

**A PREVIEW, NOT AN ENGINE. It computes nothing.** Published so a tool layer
(Prism's doMath) can depend on the frozen contract from Packagist while the
real evaluator is built. `StubEngine` returns schema-valid output in which every
result is `#N/A` with a "stub" detail and the engine string is
`fancy-formula 0.0.0-stub`. **Never price, total or report anything with this
version.** The first real evaluator is 0.1.0.

This is phase P0: the contract and the oracle, with no evaluator.

### Fixed

- **The capture workbook is rebuilt with holy-sheet 2.11.3**, and the dev
  requirement is now `^2.11.3`. The first build wrote each row's cells in
  column-letter order (A, AA, ..., B), and Google Sheets silently dropped 184
  of its input cells on import, so the first capture computed over blanks and
  was refused. Same cases and ids; only the workbook bytes and its digest in
  `request.json` changed.

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
