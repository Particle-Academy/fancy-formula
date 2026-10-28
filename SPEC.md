# fancy-formula specification

**Spec version 1. Status: P0 — frozen shapes, open semantics.**

This file is the specification. The implementations follow it; they do not
follow each other. PHP is first, TypeScript and Python follow against the same
conformance tables (fancy-conformance `formula/*`).

What is **frozen** in spec version 1, and what Prism's `doMath` builds against:

- the four JSON Schemas in `schema/` (`request`, `check-report`, `evaluation`,
  `trace`), each with examples the test suite validates;
- the stable codes in section 9;
- the outcome rules in section 2.

What is **not** settled: every Google Sheets behaviour marked **HYPOTHESIS**
below. Each one names the capture rows that decide it (section 13). Until the
capture is run, a hypothesis is a guess borrowed from Excel or LibreOffice, and
nothing may be built that depends on it being true.

The design and its decisions (D1-D17, all accepted 2026-10-28) are in the
envelope's `.ai/plans/fancy-formula.md`.

---

## 1. Scope

A deterministic spreadsheet-formula engine with **Google Sheets semantics**,
US locale. It takes formulas, which are code supplied by an author or a model,
and typed inputs, which are always values. It returns results and, per output,
a trace of what was read and which lookups ran.

The engine is pure: no I/O, no `eval`, no reflection, no host callbacks except
the providers a host registers itself (section 11). Nothing in it is
agent-specific.

API surface (PHP; the other languages mirror the shapes):

| Call | Data needed | Returns |
|---|---|---|
| `Formula::parse($src)` | none | a parsed formula, or throws `FormulaError` |
| `->normalized()`, `->ast()`, `->references()` | none | canonical text, JSON AST, references (section 3.5) |
| `Engine::check($request)` | declarations only | a check report (`schema/check-report.schema.json`) |
| `Engine::evaluate($request)` | declarations and values | an evaluation (`schema/evaluation.schema.json`) |
| `Engine::functions()` | none | the inventory: name, category, phase, minArgs, maxArgs, volatile, external, returnsArray |

`StubEngine` exists in P0 so hosts can build against the shapes now. It computes
nothing: every result is a `#N/A` value whose detail says "stub", and its engine
string is `fancy-formula 0.0.0-stub`.

---

## 2. Outcomes

Three outcomes exist, and **they never collapse into one another**. This is
fancy-expr's "unresolved is null, malformed THROWS" rule carried over to
spreadsheets.

| Outcome | When | How it surfaces |
|---|---|---|
| **Malformed** | the formula cannot be lexed or parsed, or breaks a parse limit | `parse()` throws `FormulaError` with a stable `errorCode`; `check()` reports it as an error diagnostic; `evaluate()` refuses with `FORMULA_CHECK_FAILED` |
| **Spreadsheet error** | `#N/A`, `#DIV/0!`, `#VALUE!`, `#REF!`, `#NAME?`, `#NUM!` (and possibly `#NULL!`, `#ERROR!`) | a **value** in a result, with a `detail` |
| **Limit broken** | a run exceeds a budget | the whole run aborts with a typed code; **no partial results** |

Consequences, each pinned by a conformance row once P1 lands:

- A parse failure is never a `#ERROR!` value.
- A `#...` value is never a diagnostic code, and a diagnostic code is never a value.
- An input that breaks its declared constraints refuses the request
  (`FORMULA_INPUT_CONSTRAINT`) before any formula runs: it is the host's data
  error, not a spreadsheet value.

---

## 3. Grammar

US locale only: that is how Sheets stores formulas, whatever the display locale.

### 3.1 Lexical

- An optional leading `=`.
- Numbers: `1`, `1.5`, `.5`, `1e5`, `1E-3`. No sign in the literal (sign is a
  unary operator). No thousands separators.
- Strings: double-quoted; `""` inside a string is one `"`.
- Booleans: `TRUE`, `FALSE` (case-insensitive). `TRUE()` and `FALSE()` are also
  functions.
- Error literals: `#N/A`, `#DIV/0!`, `#VALUE!`, `#REF!`, `#NAME?`, `#NUM!`;
  `#NULL!` and `#ERROR!` only if H-16 holds.
- Function names are case-insensitive and may contain dots (`CEILING.MATH`,
  `ERROR.TYPE`) and underscores (`UNARY_PERCENT`).
- Whitespace between tokens is ignored.

### 3.2 References

- `A1`, `$A$1`, `A1:B5`, open column ranges `A:A`, open row ranges `1:1`.
- Sheet-qualified: `Sheet!A1`, `'My Sheet'!A1:B2` (`''` escapes a quote).
- Named ranges and named inputs: bare identifiers (`price_breaks`). A name may
  not read as a cell address (`A1`, `AB12`): the grammar could not tell them
  apart.
- An open range is clamped to the **populated extent** of the cells bound in
  that column or row, and that extent is still checked against limits.
  **HYPOTHESIS H-25**: Sheets sizes an open range by the grid instead, so
  `ROWS(B:B)` is a divergence candidate.

### 3.3 Operators, lowest precedence first

| Level | Operators | Associativity |
|---|---|---|
| 1 | `=` `<>` `<` `>` `<=` `>=` | left |
| 2 | `&` | left |
| 3 | `+` `-` | left |
| 4 | `*` `/` | left |
| 5 | `^` | left (**HYPOTHESIS H-02**) |
| 6 | postfix `%` | — |
| 7 | unary `-` `+` | — |
| 8 | `:` (range) | — |

**HYPOTHESIS H-01**: unary minus binds tighter than `^`, so `=-2^2` is `4`, as
in Excel. `=0-2^2` is `-4` either way.

Postfix `%` divides by 100 and binds tighter than `^` (`=2^50%` is `2^0.5`).

### 3.4 Calls, arrays, LET and LAMBDA

- Arguments separate with `,`; an argument may be empty (`SUM(1,,2)`,
  `INDEX(r,,1)`), and an empty argument is distinct from an omitted one.
- Array literals: `{1,2;3,4}` — `,` separates columns and `;` rows; rows must
  be the same length. **HYPOTHESIS H-21**: elements may be references and
  expressions (`{TRUE,A1}`), which MOIC's SUMPRODUCT form needs.
- `LET(name, value, ..., body)` and `LAMBDA(params..., body)` are **syntax
  nodes**, not calls, so the analyser can scope their names. LAMBDA is P2.
- Anything else — `|`, `[`, `{` outside an array literal, a DDE form such as
  `=cmd|' /C calc'!A0` — is `FORMULA_SYNTAX` with a byte offset.

### 3.5 References as a static question

`references()` answers with **no data and no run**: names, cells, ranges,
functions, volatile functions, external functions, and whether a dynamic
reference (INDIRECT, OFFSET) appears. Each list is sorted and unique. It reads
**both** branches of IF, IFS and SWITCH. LET and LAMBDA parameter names are not
references.

---

## 4. Values

A closed set of kinds, serialised in the exchange cell vocabulary
(fancy-conformance `suites/exchange/SPEC.md` section 3.2), so holy-sheet's
exchange `read` output feeds straight in.

| Kind | JSON | Notes |
|---|---|---|
| number | `{"t":"number","v":9.75}` | an IEEE-754 double. May carry `format`: `date`, `datetime`, `percent`, or `currency` with a code |
| text | `{"t":"string","v":"..."}` | |
| bool | `{"t":"bool","v":true}` | |
| error | `{"t":"error","v":"#N/A","detail":"..."}` | `detail` mirrors the Sheets tooltip and is NOT contract until captured |
| empty | `{"t":"empty"}` | distinct from `""` |
| array | `{"t":"array","rows":[[...]]}` | rectangular; a range reference is a lazy view until materialised. Array outputs are P2 (section 9.3) |
| lambda | none | can never be an output; reaching one is a `#VALUE!` value |

**HYPOTHESIS H-35**: `TYPE` of an error is 16 and of an array literal is 64.

A number tagged `date` or `datetime` is also reported with a `display` of
`YYYY-MM-DD` or `YYYY-MM-DDTHH:MM:SS`.

---

## 5. Coercion

Every cell of this table is a HYPOTHESIS taken from Excel and LibreOffice
behaviour; the C- rows decide each one.

| to / from | number | text | bool | empty | error |
|---|---|---|---|---|---|
| **number** (operators, scalar args) | itself | parse if numeric (H-24) else `#VALUE!` | 1 / 0 | 0 | propagate |
| **text** (`&`, text functions) | 15-significant-digit general form (H-23) | itself | `TRUE` / `FALSE` | `""` | propagate |
| **bool** (IF, AND, ...) | not 0 | `"TRUE"`/`"FALSE"` in any case (H-18) else `#VALUE!` | itself | FALSE | propagate |

- **H-24**: numeric text accepts a plain decimal, `5%` -> 0.05, date-like text
  -> a serial (en_US), and surrounding spaces. Whether `"1,000"`, `"$5"`,
  `"(5)"` and `"1e3"` coerce is open. Empty TEXT (`""`) is not an empty cell.
- **H-12**: aggregates over **ranges** skip text, booleans and empties;
  aggregates over **literal arguments** coerce them (`SUM(1,"2",TRUE)` = 4 in
  Excel). Whether an array literal behaves like a range or like literals is
  open.
- **H-13**: comparison across types orders number < text < bool.
- **H-14**: text comparison and lookup are case-insensitive, including
  non-ASCII letters.
- **H-19**: an empty value compares as `0` against a number and as `""` against
  text.
- **H-20**: when several arguments are errors, the first in evaluation order
  wins.
- **H-10**: AND and OR do not short-circuit.
- **H-11**: a multi-cell range or array in a scalar context without ARRAYFORMULA
  takes its first element, or errors.
- **H-15**: LEN, LEFT, MID count UTF-16 code units (an emoji outside the BMP is 2).

Text inputs are data and are **never** parsed: see section 11.

---

## 6. Floating point

Sheets stores doubles and displays 15 significant digits.

1. **Every number is a double internally.** Host integers convert at the
   boundary, so integer overflow and `(int)` casts of large floats cannot occur.
2. **NaN and infinity never surface.** An operation that would produce them
   yields `#NUM!` (or `#DIV/0!` for division by zero). `-0` is normalised to `0`
   at output and in comparisons.
3. **No native rounding or formatting built-in is used for spreadsheet
   semantics.** ROUND, ROUNDUP, ROUNDDOWN, MROUND, TRUNC, TEXT and `&`
   stringification all go through one algorithm:
   - render the double to 15 significant digits with a correctly rounded
     scientific format (`sprintf('%.14e')` in PHP, `toExponential(14)` in JS,
     `format(x, '.14e')` in Python — all correctly rounded, so they agree bit for
     bit);
   - round that **decimal string** half away from zero.

   **HYPOTHESIS H-05**: Sheets behaves this way, so `ROUND(1.005,2)` is `1.01`
   where native rounding gives `1`. MOD takes the sign of the divisor
   (**H-29**), never `fmod`'s sign of the dividend.
4. **One comparator holds the whole equality and ordering policy**
   (`Compare::numbers()`): compare after rounding both sides to 15 significant
   digits. **HYPOTHESIS H-03**: `=0.1+0.2=0.3` is TRUE. **H-04**: a result
   within 15 significant digits of zero snaps to 0. Lookups (MATCH, approximate
   VLOOKUP) and criteria use the same comparator.
5. **Transcendental functions** (`^` with a non-integer exponent, EXP, LN, the
   distributions, iterative solvers) may differ in the last bit across libm
   implementations. Their conformance rows declare a row-level `tolerance`.
6. **Money is a double**, as in Sheets (D7). Hosts convert results to minor
   units with fancy-conformance's `shared/money-minor-units` contract.

**HYPOTHESIS H-17**: the xlsx Sheets exports keeps 17 significant digits. The
canary `=0.1+0.2` (K-0002) answers it: `0.30000000000000004` means full
precision, `0.3` means 15 digits, in which case numeric goldens compare at 15
significant digits through a row `tolerance` and the manifest says so.

---

## 7. Dates

- A serial counts days since **1899-12-30**: serial 0 is 1899-12-30, serial 1
  is 1899-12-31. Google's DATE help (support.google.com/docs/answer/3092969,
  fetched 2026-10-27) says so, and that years 0-1899 have 1900 added
  (`DATE(119,2,1)` is 2019-02-01) and years below 0 or from 10,000 are `#NUM!`
  (**H-08**).
- **HYPOTHESIS H-06** (inferred, not documented): Sheets has **no 1900
  leap-year bug**. Serial 60 is 1900-02-28 and serial 61 is 1900-03-01. Excel's
  serial 60 is the fictitious 1900-02-29; the two agree from 61 on.
- **HYPOTHESIS H-07**: negative serials. **H-26**: two-digit years in date text.
- Calendar arithmetic uses integer days-to-civil algorithms (Hinnant), identical
  in every language. No runtime date library and no time zone database are
  involved.
- Worked check: 2026-10-27 is serial **46322** (D-0014).
- Known LibreOffice divergence, for the divergence ledger: LibreOffice reads
  two-digit years through a 1930-2029 window.

---

## 8. Requests and inputs

The request shape is `schema/request.schema.json`. Formulas and inputs bind to
a **name** or to a sheet-qualified **cell or range** (`Sheet!A1`,
`Sheet!A1:B5`). `names` declares named ranges that alias cells. A cell that no
input binds reads as empty.

`check()` takes the same request with `value` and `rows` omitted (a range may
give `maxRows`); that is the save-time call.

### 8.1 Input types

| type | `value` accepted | becomes |
|---|---|---|
| `number` | a JSON number, or a decimal string `^-?\d+(\.\d+)?$` | double |
| `percent` | a fraction (`0.05`) or a string ending in `%` (`"5%"`) | double tagged percent; a bare number above 1 gets warning `FORMULA_PERCENT_SUSPICIOUS` (D6) |
| `money` | a decimal string or number, plus `currency` (three capitals) | double tagged currency; mixing currencies in `+` `-` gets warning `FORMULA_CURRENCY_MIXED` |
| `text` | a string | text, **never parsed** |
| `boolean` | `true` / `false` | bool |
| `date` / `datetime` | `YYYY-MM-DD` / `YYYY-MM-DDTHH:MM:SS`, a real calendar date | serial number tagged date |
| `range` | `rows` (rectangular); each cell a JSON scalar, `null` (empty), a typed `{type,value}` or an exchange `{t,v}`; optional `header`, `columns`, `currency` | array |
| `table` | an exchange `table` item, as-is | array; spans flatten into the anchor cell; cell `formula` metadata is ignored and recorded as `formulaMetadataIgnored` |

An input whose value does not fit its declared type (`{"type":"number","value":"=1+1"}`)
is `FORMULA_INPUT_INVALID`. A declared `text` used in arithmetic is warning
`FORMULA_TYPE_COERCES`; a use that cannot coerce is `FORMULA_TYPE`.

Two cell-bound inputs that overlap must agree cell for cell, or the request is
`FORMULA_INPUT_INVALID`.

### 8.2 Input constraints (MOIC, 2026-10-28; in the engine schema)

**The agent is never the validator.** Each declaration may carry `constraints`.
They are part of the frozen request schema, so the TypeScript and Python ports
get them through the conformance tables; doMath passes them through and does not
re-implement them.

| Rule | Applies to | Meaning |
|---|---|---|
| `required: true` | all | absent otherwise reads as empty |
| `integer: true` | number | |
| `min`, `max` (inclusive) | number, percent, money, date | dates as `YYYY-MM-DD` strings |
| `oneOf: [...]` | scalars | |
| `columns: [type...]` | range, table | each cell fits its column type |
| `key: {column, sorted, unique}` | range, table | `column` is 1-based; `sorted` is `ascending` or `descending` |
| `indexInto: "<range input>"` | number | an integer within `1..ROWS(range)` |
| `oneOfRange: "<range input>"` | scalars | the value appears in that range |
| `requiredWhen: {input, oneOf}` | all | required only when another input's value is one of `oneOf` (8.2.1) |
| `columnConstraints: {"<column>": {...}}` | range, table | per-column rules for the data cells (8.2.2) |

A range's top-level `columns` declares how cells are typed (money tagging);
`constraints.columns` refuses a value whose cells do not fit. Both may be given;
if both are, they must be equal, or `check()` reports `FORMULA_INPUT_INVALID`.

**Membership** — `oneOf`, `oneOfRange`, `requiredWhen`, a column's `oneOf`,
`oneOfColumn` and its `where` — uses the engine's own equality, so a constraint
agrees with what a formula comparing the same values would decide: numbers
through `Compare::numbers()` (15 significant digits), text case-insensitively
(H-14, whatever the capture decides), dates and datetimes as their canonical
strings, and no equality across kinds (the number `1` never matches the text
`"1"`). An empty cell is never a member of anything.

- `check()` validates the **declared** shape: contradictory rules (`min` above
  `max`, `oneOf` outside `min..max`), and `indexInto` / `oneOfRange` naming an
  input that is missing or not a range, plus the `requiredWhen` and
  `columnConstraints` cases below. Each is a `FORMULA_INPUT_INVALID`
  diagnostic.
- `evaluate()` validates the **supplied** values **before any formula runs**.
  Any violation refuses the whole request:
  `{ok: false, code: "FORMULA_INPUT_CONSTRAINT", violations: [...]}`, each
  violation `{input, rule, ...}` — for example
  `{input: "term_years", rule: "oneOf", allowed: [3, 5], got: 4}` or
  `{input: "grid_5yr", rule: "key.sorted", row: 4}`. Rules in a violation are
  `required`, `integer`, `min`, `max`, `oneOf`, `indexInto`, `oneOfRange`,
  `columns`, `key.sorted`, `key.unique`, `type`, `requiredWhen`,
  `column.required`, `column.integer`, `column.min`, `column.max`,
  `column.oneOf` and `column.oneOfColumn`.
- **No partial results.** Every rule gets a passing and a failing row in
  `formula/check`; a mutant that skips constraint validation must fail them.

#### 8.2.1 `requiredWhen` (MOIC, 2026-10-28)

```json
"admin_price_entered": {
  "type": "money", "currency": "USD",
  "constraints": { "requiredWhen": { "input": "admin_mode", "oneOf": ["entered"] }, "min": 0 }
}
```

- The input is required exactly when the **controlling input** was supplied
  and its value is a member of `oneOf`. The controller is compared after its
  own type conversion (`"5%"` is `0.05`, money `"950"` is `950`).
- **An absent controller never triggers the requirement.** Absent reads as
  empty, and empty is never a member. A host that needs the controller gives
  it its own `required: true`; that is the one way to say so, and it reports
  its own `required` violation. A controller that breaks its own rules (say,
  a value outside its `oneOf`) is still compared: both violations are reported.
- Violation: `{input, rule: "requiredWhen", when: {input, value}}` — the
  controller and the value that triggered it.
- `required: true` beside `requiredWhen` makes the input always required.
- The controller is an **input**, never a formula: constraints run before any
  formula does. Chains and cycles (`a` requiredWhen `b`, `b` requiredWhen `a`)
  are harmless, because only supplied values are compared.
- `check()` reports `FORMULA_INPUT_INVALID` when `requiredWhen.input` names an
  input that is not declared, names the input itself, names a range or table
  (a controller is a scalar), or lists a value the controller can never hold
  (wrong kind for its type, or outside the controller's own `oneOf`).
- **No `equals` sugar.** `oneOf: ["entered"]` costs one bracket pair more than
  `equals: "entered"`; a second spelling would cost a code path, a
  both-given rule and a conformance row in every port. The schema refuses
  `equals`.

#### 8.2.2 `columnConstraints` (MOIC, 2026-10-28)

```json
"modules": {
  "type": "range", "header": true,
  "columns": ["text", "text", "text", "number", "money"], "currency": "USD",
  "constraints": {
    "key": { "column": 1, "unique": true },
    "columnConstraints": {
      "kind":        { "required": true, "oneOf": ["Core", "Advanced"] },
      "core_module": { "oneOfColumn": { "column": "name", "where": { "column": "kind", "oneOf": ["Core"] } } },
      "4":           { "required": true, "integer": true, "min": 0 }
    }
  }
}
```

- **A column key** is a 1-based index written as digits (`"4"`), or a header
  text when the range or table has a header row. All-digit text always means
  an index, so a header literally named `"2"` is reached by its index. Header
  text matches **exactly** (headers are identifiers, not data). Inside a rule,
  `column` is an integer or a header text.
- **Per column**: `required` (no data cell is empty), `integer`, `min`, `max`
  (inclusive; numbers, or `YYYY-MM-DD[THH:MM:SS]` strings for a date or
  datetime column), `oneOf`, and `oneOfColumn`. The header row is exempt from
  every rule. An empty cell is checked only by `required`.
- **`oneOfColumn: {input?, column, where?}`**: each non-empty cell must be a
  member of the non-empty values in `column` of `input` — by default this same
  range — among the target's data rows that `where` admits.
  **`where: {column, oneOf}`** is ONE membership filter on the target's rows,
  and the only filter there is. No operators, no second condition, no
  predicates; the schema refuses each. It ships because it is a set filter
  built in one pass, and MOIC's "core modules only" needs it.
- **One home per rule, reconciled with what already existed**: cell types stay
  in `columns` (top-level and `constraints.columns`); ordering and uniqueness
  stay in `key`. A column rule carries neither, and the schema refuses
  `type`, `sorted` and `unique` inside one. `integer`, `min` and `max` need the
  column's declared type to be `number`, `percent` or `money` (or `date` /
  `datetime` for string bounds), since an undeclared column has no numbers to
  compare.
- **Violations** name the input, the rule, the row and the column:
  `{input: "modules", rule: "column.oneOfColumn", row: 3, column: 3, header: "core_module", got: "Analytics", range: "modules"}`.
  `row` is 1-based and counts the header row (INDEX numbering, as in the
  trace); `column` is always the resolved index, and `header` is added when the
  range has one. `got` is the cell, `allowed` / `min` / `max` what was
  allowed, `range` the target of `oneOfColumn`. Within one input, violations
  are row-major, then by column, then in the order `required`, `integer`,
  `min`, `max`, `oneOf`, `oneOfColumn`.
- A column key or rule column that does not resolve against the **supplied**
  data — a header text the header row lacks or holds twice, an index past the
  supplied width — is a `columns` violation carrying that `column` or
  `header`.
- `check()` reports `FORMULA_INPUT_INVALID` for: a header text on a range with
  no header row; an index past a declared width (`columns`); `integer`, `min`
  or `max` on a column whose declared type cannot hold them; `min` above
  `max`, or `oneOf` outside them; `oneOfColumn.input` naming an input that is
  missing or not a range or table; and a `oneOfColumn` on its own column of its
  own range with no `where`, which can never fail.

### 8.3 Host policy is never in the request

Limits, clock, seed, providers and the function policy are **constructor state
built by the host** (exchange SPEC section 13). A request may only **tighten**
limits — the stricter value wins — and can never loosen one, add a provider or
supply a clock. The schema refuses those keys outright.

---

## 9. Results and stable codes

### 9.1 Results

`schema/evaluation.schema.json`. Exactly one of:

- **success** — `{ok: true, engine, spec: "1", fingerprint, results, traces, warnings}`.
  `results[binding] = {value, display?}`; `display` is the 15-significant-digit
  general form. Formatting money for a document is the host's job. An array
  output's result is shaped by section 9.3 and has no `display`.
- **check failed** — `{ok: false, code: "FORMULA_CHECK_FAILED", message, diagnostics}`.
  `evaluate()` runs `check()` first and refuses on any error diagnostic.
- **constraint refusal** — section 8.2.
- **abort** — `{ok: false, code, message, at?, limit?}` with a limit code.

`fingerprint` is `sha256:` over the canonical JSON of the normalised formulas,
inputs, clock value, seed digest, provider responses, engine and spec version.
Same fingerprint, same results: Prism caches and audits on it.

### 9.2 Codes

One `FormulaError` class carries `$errorCode` (not `$code`, which `Exception`
owns). It is thrown only for parse codes. Every other code appears in a report.

| Kind | Severity / channel | Codes |
|---|---|---|
| Parse | thrown by `parse()`; error diagnostic in `check()` | `FORMULA_SYNTAX`, `FORMULA_TOO_LONG`, `FORMULA_TOO_DEEP` |
| Check | error diagnostic | `FORMULA_UNKNOWN_FUNCTION`, `FORMULA_UNKNOWN_NAME`, `FORMULA_CIRCULAR` (with the cycle path in `related`), `FORMULA_ARITY`, `FORMULA_TYPE`, `FORMULA_REFUSED_FUNCTION`, `FORMULA_NEEDS_CLOCK`, `FORMULA_NEEDS_SEED`, `FORMULA_INPUT_INVALID`, `FORMULA_DYNAMIC_REFERENCE` |
| Warning | warning diagnostic; never stops a run | `FORMULA_TYPE_COERCES`, `FORMULA_PERCENT_SUSPICIOUS`, `FORMULA_CURRENCY_MIXED`, `FORMULA_LOOKUP_UNSORTED` |
| Limit | evaluate abort | `FORMULA_LIMIT_RANGE`, `FORMULA_LIMIT_ARRAY`, `FORMULA_LIMIT_LAMBDA_DEPTH`, `FORMULA_LIMIT_STEPS`, `FORMULA_LIMIT_TEXT`, `FORMULA_LIMIT_CELLS`, `FORMULA_LIMIT_TIME` |
| Refusal | evaluate, before any formula | `FORMULA_INPUT_CONSTRAINT` (with `violations`), `FORMULA_CHECK_FAILED` (with `diagnostics`) |

A diagnostic is `{code, severity: error|warning, target, offset?, message, related?}`;
`offset` is a byte offset into that target's formula. `check().ok` is false
exactly when an error diagnostic exists.

`FORMULA_CURRENCY_MIXED` and `FORMULA_CHECK_FAILED` are named here: the plan
describes a currency-mixing warning and an evaluate() that refuses on check
errors, but names no code or shape for either. Every other code is the plan's.
The 2026-10-28 additions (`requiredWhen`, `columnConstraints`, array outputs)
added rules and shapes and **no code**.

### 9.3 Array outputs (MOIC, 2026-10-28)

```json
"outputs": {
  "module_lines": { "type": "array", "columns": ["text", "text", "text", "number", "money"], "currency": "USD", "maxRows": 100 }
}
```

- An output may be `{type: "array", columns: [type...], maxRows?, currency?}`.
  Each column is declared exactly as a scalar output of that type would be;
  `currency` tags the `money` columns. `columns` is required for an array
  output and `columns` / `maxRows` are refused on a scalar one.
- Its result is `{value: {t: "array", rows: [[cell...]...]}}`, rectangular,
  each cell a scalar value. An array result has **no `display`**: the schema
  refuses one.
- **Shape is a value question, never an abort.** In the engine that produces
  arrays (P2):
  - an **error** value (FILTER matching nothing is `#N/A`) is reported as that
    scalar error, not wrapped: an error is a legal result for any output;
  - any other scalar becomes a 1x1 array;
  - a result whose column count differs from `columns`, or whose rows exceed
    `maxRows`, is a `#REF!` **value** whose detail says which — Sheets'
    answer for an array with no room to spill;
  - the cell count is still bounded by `maxArrayCells`, which aborts with
    `FORMULA_LIMIT_ARRAY` as for any array.
- **A scalar output that receives an array** — the formula's top-level result
  is a range or array, as `=Inputs!C2:D5` or `=FILTER(...)` is — reports its
  single element when it is 1x1, and otherwise a `#REF!` value whose detail
  says an array reached a scalar output. This holds in P1 and P2 alike: a
  scalar output never spills. It concerns the top-level result only; an array
  in a scalar **argument** is H-11. It is engine-defined, not captured: every
  capture row returns a scalar.
- **P1 does not produce arrays.** A P1 engine's `check()` reports
  `FORMULA_TYPE` (error) on every output declared `type: "array"`, with a
  message saying array outputs arrive in P2, so `evaluate()` refuses with
  `FORMULA_CHECK_FAILED`. A host can write the declaration today and get a
  definite refusal, never a silently scalar answer. `StubEngine` returns the
  P2 shape — one row of stub `#N/A` errors, one per declared column — so a
  host can build its rendering now.

---

## 10. Trace

`schema/trace.schema.json`: one trace per reported output. Levels: `none`,
`lookups` (default: reads plus lookup and criteria steps) and `full` (every
function call).

- `reads`: what the formula read — a scalar inline, a range by `shape` and
  `digest`.
- `steps[].lookup`: the key, the range, the `match` (`null` when nothing
  matched), the approximate-match `bracket` (`from` row and key, `to` row and
  key, `to` null on the last row), what was `returned`, whether the key column
  was `sorted`, and how many `probes` the search made.
- **Row and column are 1-based within the range and count the header row**, as
  INDEX numbers them. `cell` is the absolute address for a cell-bound range and
  `null` for a name-bound one.
- `sorted: false` still runs Sheets' search exactly, so the result agrees with
  Sheets on unsorted data, and adds warning `FORMULA_LOOKUP_UNSORTED`.
  **HYPOTHESIS H-09**: Sheets binary-searches an unsorted approximate lookup.
- Criteria functions record `{criteria, parsed: {op, value}, matchedRows}`;
  `matchedRows` stops at 1,000 entries and sets `truncated`.
- The trace has its own step limit (default 10,000); hitting it sets `truncated`
  and never aborts.

Appendix A's footnote — "Discount 5%: 3-year grid, 25-49 units row; plus 5%
acceleration (option 2)" — is the lookup `bracket` (from 25, to 50) plus the
INDEX read of `accel_options[2]`.

---

## 11. Security

### 11.1 Runtime surface

- Functions dispatch from a **closed registry** keyed by upper-case name. A user
  string never selects a host method.
- No `eval`, no `include`, no I/O, no reflection.
- External functions (IMPORTXML, IMPORTHTML, IMPORTDATA, IMPORTRANGE,
  IMPORTFEED, GOOGLEFINANCE, GOOGLETRANSLATE, DETECTLANGUAGE, IMAGE) are
  `FORMULA_REFUSED_FUNCTION` at check time unless the host registered a provider
  under that name. A provider does the I/O; each call and response is recorded
  in `trace.external` and fingerprinted, so a run replays from its trace.
- INDIRECT and OFFSET are `FORMULA_DYNAMIC_REFERENCE` by default (D9).
- HYPERLINK is pure: it returns its label and fetches nothing.

### 11.2 Text inputs are data

- Input values never reach the lexer. `{"type":"text","value":"=1+1"}` is the
  four-character string `=1+1`.
- **The one deliberate exception**: criteria strings in the criteria functions
  (`">=100"`, wildcards `* ? ~`) and TEXT format codes **are** interpreted when
  they come from inputs, because that is Sheets semantics. Each is a small
  closed grammar with no reach into references or functions, and the trace
  records the parsed criteria. **HYPOTHESIS H-28**: criteria `"=SUM(A1)"` means
  "equals the text `SUM(A1)`".
- The `formula/security` rows (S-) cover text starting with `=`, `+`, `-`, `@`,
  TAB and CR, used in `&`, LEN, lookups and criteria.

### 11.3 Clock and seed

- NOW and TODAY without a host clock are `FORMULA_NEEDS_CLOCK`; RAND,
  RANDBETWEEN and RANDARRAY without a seed are `FORMULA_NEEDS_SEED`.
- The clock gives a local date-time and an IANA zone; the engine converts it to
  a serial with its own integer calendar code.
- Random draws use **xoshiro128\*\***, state = the first 16 bytes of SHA-256 of
  the host's seed string, every operation masked to 32 bits. Draws happen in
  evaluation order: topological, then left to right, then row-major in arrays.
  The same seed and request give the same numbers in every language, and
  deliberately not Sheets' numbers (D8). Volatile rows are therefore AUTHORED,
  never captured, and their manifest says so.

### 11.4 Output injection (the engine writes no files)

| Where | Rule | Owner |
|---|---|---|
| CSV / TSV | string or error fields beginning `= + - @ TAB CR` get a `'` prefix by default, already in exchange `toCsv` (exchange SPEC section 12) | exchange |
| xlsx via holy-sheet | write results only as an explicit `{value: s}` or an exchange cell, never a bare string: a bare `=...` string is promoted to a formula. No `'` inside xlsx | fancy-formula helper + holy-sheet |
| a trace's `formula` field | the same: surfaced into a document it is text | the host |
| JSON | nothing needed | — |

---

## 12. Limits

Defaults are the host's. A request can only tighten them; the hard ceiling can
never be exceeded (the request schema enforces the ceilings).

| Limit | Request key | Default | Ceiling | Code |
|---|---|---|---|---|
| Formula length (UTF-8 bytes, before lexing) | `maxFormulaBytes` | 8,192 | 65,536 | `FORMULA_TOO_LONG` |
| Parse nesting depth (fancy-expr's definition) | `maxDepth` | 64 | 256 | `FORMULA_TOO_DEEP` |
| Formulas per request | `maxFormulas` | 500 | 5,000 | `FORMULA_INPUT_INVALID` |
| Cells in one range reference | `maxRangeCells` | 100,000 | 1,000,000 | `FORMULA_LIMIT_RANGE` |
| Total input cells | `maxInputCells` | 1,000,000 | 5,000,000 | `FORMULA_LIMIT_CELLS` |
| Cells in one array result | `maxArrayCells` | 100,000 | 1,000,000 | `FORMULA_LIMIT_ARRAY` |
| LAMBDA call depth | `maxLambdaDepth` | 64 | 256 | `FORMULA_LIMIT_LAMBDA_DEPTH` |
| Evaluation steps (node visits plus per-cell work) | `maxSteps` | 1,000,000 | 50,000,000 | `FORMULA_LIMIT_STEPS` |
| Text value length (**HYPOTHESIS H-34**) | `maxTextLength` | 50,000 chars | 50,000 | `FORMULA_LIMIT_TEXT` |
| Wall clock (checked every 1,024 steps) | `wallClockMs` | 1,000 ms | 30,000 ms | `FORMULA_LIMIT_TIME` |
| Trace steps | `maxTraceSteps` | 10,000 | 100,000 | sets `truncated` |

Iterative solvers (IRR, RATE, XIRR, YIELD) have their own iteration cap matched
to Sheets; not converging is a `#NUM!` **value**. Steps are the deterministic
guard; the wall clock is a backstop and the only limit whose outcome can vary
between runs, which is why it has its own code.

---

## 13. Hypotheses the capture decides

Every row below is unverified. The capture workbook (`capture/v1/`) holds the
rows that decide each one; `request.json` lists the same mapping, and a test
holds this table and that file to the same ids.

| Id | Hypothesis | Decided by |
|---|---|---|
| H-01 | Unary minus binds tighter than ^, so =-2^2 is 4 (as in Excel). | P-0001, P-0002, P-0004 |
| H-02 | ^ is left-associative, so =2^3^2 is 64. | P-0003 |
| H-03 | Equality compares at 15 significant digits, so =0.1+0.2=0.3 is TRUE. | F-0001, F-0005, F-0016, F-0024, F-0027, F-0028, F-0029, F-0030, F-0031, F-0032, F-0033, L-0015 |
| H-04 | A result within 15 significant digits of zero snaps to 0, e.g. (0.1+0.2)-0.3. | F-0002, F-0034 |
| H-05 | ROUND, ROUNDUP, ROUNDDOWN, MROUND and TRUNC work on the 15-significant-digit decimal string, rounding half away from zero (ROUND(1.005,2) = 1.01). | F-0006, F-0007, F-0008, F-0009, F-0010, F-0011, F-0012, F-0013, F-0014, F-0015, F-0035, F-0036, E-0081, E-0092, E-0366, E-0375, E-0379 |
| H-06 | Sheets has no 1900 leap-year bug: serial 60 is 1900-02-28 and DATE(1900,2,29) is 1900-03-01. | D-0001, D-0002, D-0003, D-0004, D-0005, D-0006, D-0007, D-0008, D-0009, D-0010, D-0028 |
| H-07 | Negative serials are dates before 1899-12-30 (or an error); TEXT/YEAR of a negative serial. | D-0011, D-0012, D-0013 |
| H-08 | DATE adds 1900 to a year in 0..1899; a year below 0 or at least 10000 is #NUM!. | D-0013, D-0015, D-0016, D-0017, D-0018, D-0019, D-0020 |
| H-09 | Approximate VLOOKUP/MATCH/LOOKUP over an unsorted column runs a binary search, and the result follows that search. | L-0001, L-0002, L-0003, L-0004, L-0005, L-0006 |
| H-10 | AND and OR do NOT short-circuit: an error in a later argument still surfaces. | E-0193, E-0196 |
| H-11 | A multi-cell range or array in a scalar context without ARRAYFORMULA takes its first element (or errors). | P-0023, E-0301 |
| H-12 | Aggregates coerce LITERAL text and boolean arguments (SUM(1,"2",TRUE) = 4) but skip them inside ranges. | C-0034, C-0035, C-0036, C-0037, C-0038, C-0039, C-0040, C-0041, C-0042, E-0138 |
| H-13 | Mixed types order as number < text < boolean in comparisons. | P-0013, C-0043, C-0044, C-0045, C-0055, C-0056, L-0007, L-0008, L-0009, E-0027, E-0030, E-0033, E-0035, E-0157, E-0222 |
| H-14 | Text comparison and lookup are case-insensitive, including non-ASCII letters. | C-0046, C-0047, C-0048, L-0020, L-0021, E-0026, E-0029, E-0038, E-0056, E-0183, E-0241 |
| H-15 | LEN, LEFT and MID count UTF-16 code units, so an emoji outside the BMP is 2. | E-0309, E-0310, E-0317, E-0325 |
| H-16 | #NULL! and #ERROR! exist as Sheets error values with ERROR.TYPE codes. | P-0030, P-0031 |
| H-17 | The xlsx Sheets exports keeps full double precision (17 significant digits). | K-0002, F-0003, F-0004, F-0019, F-0021, F-0023, F-0025, F-0026 |
| H-18 | The text "TRUE" / "FALSE" (any case) coerces to a boolean in IF/AND/OR/NOT; other text is #VALUE!. | C-0026, C-0027, C-0028, C-0029, C-0032, C-0033, E-0173, E-0194, E-0197, E-0200 |
| H-19 | An empty cell compares as 0 against a number and as "" against text. | C-0006, C-0017, C-0049, C-0050, C-0051, C-0052, L-0010, E-0036, E-0039, E-0041 |
| H-20 | When several arguments are errors, the first in evaluation order wins. | C-0057, C-0058, C-0059, C-0060, C-0061 |
| H-21 | Cell references are accepted inside array literals ({TRUE,A1}), as MOIC's SUMPRODUCT form needs. | P-0029, A-0011, A-0025, A-0046 |
| H-22 | INDEX with a row past the range is #REF! (MOIC expects #REF!; Sheets may say #NUM!). | E-0235, A-0029, A-0030, A-0031 |
| H-23 | Number-to-text (& and text functions) uses the 15-significant-digit general form (1/3 -> 0.333333333333333). | C-0015, C-0019, C-0020, C-0021, F-0017, F-0018, F-0020, E-0302 |
| H-24 | Numeric text coerces in arithmetic: "5%" -> 0.05, date-like text -> serial, surrounding spaces allowed; thousands separators and currency symbols are a question. | P-0015, C-0001, C-0002, C-0003, C-0004, C-0008, C-0009, C-0010, C-0011, C-0012, C-0013, C-0014, S-0008, E-0362 |
| H-25 | An open range (B:B) is sized by the populated extent of the inputs (the engine) -- Sheets sizes it by the grid, so ROWS(B:B) is a known divergence candidate. | P-0027, L-0016, L-0017 |
| H-26 | Two-digit years in date TEXT ("1/1/29", "1/1/30") follow a fixed pivot in Sheets. | D-0021, D-0022 |
| H-27 | INDEX with an omitted row argument (INDEX(r,,1)) returns the whole column, as MOIC's XLOOKUP form needs. | E-0237, A-0003, A-0017, A-0022, A-0033, A-0038 |
| H-28 | Criteria strings are a closed grammar: "=SUM(A1)" means equality with the TEXT SUM(A1), and "==1+1" matches the text =1+1. | S-0011, S-0012, S-0013, S-0014 |
| H-29 | MOD takes the sign of the divisor (not fmod's sign of the dividend). | E-0109, E-0110 |
| H-30 | CEILING and FLOOR with a positive number and negative factor (or the reverse) follow Sheets, not Excel's #NUM!. | E-0084, E-0091 |
| H-31 | IFERROR with value_if_error omitted returns an empty string. | E-0186 |
| H-32 | POWER(0,0) is 1. | E-0118 |
| H-33 | SUMPRODUCT over arrays of different sizes is an error, not #N/A padding. | E-0063 |
| H-34 | A text value longer than 50,000 characters is an error. | E-0312 |
| H-35 | The TYPE of an error value is 16 and the TYPE of an array literal is 64. | E-0291, E-0292 |

Also open, and decided outside the capture:

- The 435-function floor count came from a summarising fetcher; recount by hand.
- Unicode collation for SORT (P2).
- How arrays broadcast and pad (P2; partly H-33).
- Whether a lambda reaching an output is `#VALUE!` (P2).
- Whether constraint violations are capped per input: a 10,000-row range can
  break a column rule on every row, and section 8.2 reports every one.
- PHP 8.4's change to `round()` — moot, because the engine never calls it.

---

## 14. Conformance and the capture

- Suites live in fancy-conformance as `formula/*`: `parse`, `references`,
  `check`, `limits`, `security`, `trace`, `coerce`, `float`, `dates`,
  `eval-operators`, `eval-math`, `eval-logical`, `eval-lookup`, `eval-info`,
  `eval-text`, `eval-date`, `eval-stat`, `eval-financial`, `eval-array`, and
  `pricing` (Appendix A).
- **Google Sheets is the only golden source** (D3). Rows come from a capture:
  `capture/v1/capture-v1.xlsx` (every formula written with no cached value) is
  uploaded, converted to a Google Sheet with locale United States and time zone
  UTC, recalculated, and downloaded as xlsx. fancy-conformance's
  `scripts/build-formula-cases.py` turns the export into `cases.json` and
  **refuses** it if any formula cell has no value, a canary is wrong, the case
  ids differ from `request.json`, or `provenance.json` records the wrong locale,
  time zone or file digests.
- Volatile functions are authored against section 11.3, never captured.
- LibreOffice is a development aid that feeds a divergence ledger; a row backed
  only by LibreOffice is never a golden.
- Capture rows reach their inputs by address (`Inputs!C2:D5`); each row's engine
  request binds the same addresses as cell-bound inputs, so the formula text is
  identical in the capture and in the conformance row.
