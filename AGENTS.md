# Using particle-academy/fancy-formula — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-formula
```

PHP 8.4+, no runtime dependencies. PHP only for now; TypeScript and Python ports
are planned.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). It is listed as the registry entry `fancy-formula` (a
package entry, not vendorable source). Find it with `search-backend-packages`
(stack `php`).

## API essentials

Use it when an agent needs spreadsheet maths (a quote, a price break) done
deterministically instead of in the model. Hand it formulas and typed inputs; get
back values and a trace of what each output read and which lookup row matched.

```php
use FancyFormula\{Engine, Options, Limits, FixedClock, Seed, FunctionPolicy};

$engine = new Engine;                   // defaults; or pass Options:
$engine = new Engine(new Options(
    limits:    Limits::defaults()->with(wallClockMs: 500),
    clock:     new FixedClock('2026-10-27T09:00:00', 'Europe/London'),
    random:    Seed::fromString('quote-8812'),
    providers: [],                      // ExternalProvider[]
    functions: FunctionPolicy::all(),   // or ::only([...]), ->deny([...])
));

$report = $engine->check($request);     // CheckReport: static analysis, no values needed
$result = $engine->evaluate($request);  // Evaluation: results + one trace per output
json_encode($result);                   // schema-valid JSON
```

A request (`schema/request.schema.json`):

```json
{
  "version": 1,
  "formulas": { "unit_price": "=VLOOKUP(qty, price_breaks, 2, TRUE)" },
  "inputs": {
    "qty": { "type": "number", "value": 120, "constraints": { "integer": true, "min": 1 } },
    "price_breaks": { "type": "range", "header": true,
                      "rows": [["min_qty","unit_price"],[1,12.5],[50,11],[100,9.75],[500,8.4]] }
  },
  "trace": "lookups"
}
```

- `Engine::functions()` lists the supported functions.
- `Formula::parse($source)` parses one formula on its own (`->references()`,
  `->ast()`, `->normalized()`).
- Exact signatures are in `SPEC.md` section 1.1; the JSON Schemas in `schema/`
  are frozen at spec version 1.

## Traps

- **Three outcomes, never one.** A malformed formula throws `FormulaError` with a
  stable `FORMULA_*` code; a spreadsheet error such as `#N/A` is a value in the
  result; a broken limit aborts the whole run with no partial results. Handle
  all three.
- **Inputs are data.** An input value that looks like a formula stays text; it
  is never evaluated.
- **Policy belongs to the host.** Limits, clock, seed, providers and the
  function allowlist are set when the engine is built; a request can only
  tighten limits.
- A function outside the implemented set reports `FORMULA_UNKNOWN_FUNCTION` at
  `check()`. Run `check()` before trusting `evaluate()`.
- `StubEngine` (from 0.0.1) computes nothing; construct `Engine`.
- Semantics follow Google Sheets, not Excel.
