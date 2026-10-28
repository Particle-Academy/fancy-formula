# fancy-formula

A deterministic spreadsheet-formula engine with **Google Sheets semantics**.
Formulas are code; inputs are always values; every output carries a trace of
what it read and which lookup row it matched.

> **Status: phase P0 — specification and oracle. There is no evaluator yet.**
> The JSON Schemas in `schema/` and the stable codes in `SPEC.md` are frozen at
> spec version 1, so hosts can build against them now. `StubEngine` returns
> schema-valid output and computes nothing. The PHP engine (0.1.0) is phase P1.

## Why

A pricing agent should not do quote maths in the model. It should hand a
formula and typed inputs to something deterministic, get back the number, and
get back WHY: "120 units falls in the 100-499 break, so the unit price is
$9.75".

Three outcomes, never collapsed into one another:

- a malformed formula **throws** with a stable code;
- a spreadsheet error such as `#N/A` is a **value**;
- a broken limit **aborts** the whole run, with no partial results.

## Shape

```php
use FancyFormula\StubEngine;

$engine = new StubEngine;              // the real Engine arrives in 0.1.0
$report = $engine->check($request);     // static analysis, no values needed
$result = $engine->evaluate($request);  // results + one trace per output
```

The request (`schema/request.schema.json`):

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

Policy — limits, clock, seed, providers, which functions are allowed — is set
by the host when it builds the engine. A request can only tighten limits.

## Conformance

Google Sheets is the only golden source. `capture/v1/capture-v1.xlsx` holds 689
formulas with no cached values; it is uploaded to Sheets, recalculated, and
exported, and fancy-conformance's `scripts/build-formula-cases.py` turns the
export into the shared `formula/*` tables. Every Sheets behaviour not yet
captured is a hypothesis in `SPEC.md` section 13.

## Why not a library

PhpSpreadsheet targets Excel and has no Google Sheets mode, no static check, no
lookup trace, no function allowlist and no execution budgets, and it would give
the TypeScript and Python ports nothing to share. The grammar is ours.

## Licence

MIT.
