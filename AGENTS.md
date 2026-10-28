# AGENTS.md — fancy-formula

A deterministic spreadsheet-formula engine with **Google Sheets semantics**:
one specification, one shared conformance table, PHP first and TypeScript and
Python later — the fancy-expr layout. `CLAUDE.md` symlinks here.

This file describes **this repo's code**. Process rules — publishing, kit
versioning, backports — live in the envelope and are deliberately not repeated.

## Read SPEC.md first

`SPEC.md` is the specification. Implementations follow it, not each other. A
disagreement between an implementation and SPEC.md is a bug in the
implementation; a disagreement between SPEC.md and a Google Sheets capture is a
bug in SPEC.md, and the capture wins.

## Where things are

```
SPEC.md                         the specification (spec version 1)
schema/*.schema.json            request, check-report, evaluation, trace: the frozen contract
php/src/FormulaError.php        the stable codes; thrown only for parse codes
php/src/StubEngine.php          P0 stand-in: schema-valid, computes nothing
php/tests/Support/SchemaValidator.php   test-only JSON Schema subset validator
scripts/capture/cases-v1.php    the capture rows (APPEND ONLY)
scripts/capture/p1-functions.php        the P1 floor: function -> conformance suite
scripts/capture/CaptureBuilder.php      rows -> holy-sheet schema + request.json
scripts/build-capture-workbook.php      writes capture/v1/
capture/v1/                     the workbook to upload, and its request.json
```

`composer.json` and `phpunit.xml` sit at the ROOT (fancy-expr's trap: a
`php/composer.json` is invisible to Packagist). Pest runs from `php/tests`, so
the test command is `vendor/bin/pest --test-directory=php/tests`; plain
`vendor/bin/pest` looks for `tests/` and exits on a missing directory.

## Invariants

**Three outcomes, never collapsed.** Malformed input throws `FormulaError`; a
spreadsheet error is a value; a broken limit aborts with no partial results. A
parse failure must never become a `#ERROR!` value.

**Codes live in three places and a test holds them equal**: `FormulaError`'s
constants, the schemas' enums, and SPEC.md. Adding a code means all three. The
SPEC.md check is a token scan for `FORMULA_` words, so do not write a code name
in SPEC.md that is not a code.

**Inputs are data.** No input value ever reaches the lexer. Text that looks like
a formula stays text — in the engine, in the capture workbook (written as
explicit string cells, never bare `=` strings that holy-sheet would promote),
and in anything a host writes back out.

**The schema validator refuses keywords it does not know.** A validator that
skipped one would pass every document that keyword exists to refuse. Adding a
keyword to a schema means teaching `SchemaValidator`.

## The capture workbook

Google Sheets is the only golden source. `cases-v1.php` is **append only**: ids
(`P-0001`, `E-0123`, ...) are assigned in declaration order per prefix and are
cited by SPEC.md, the schemas, and later fancy-conformance. `CaptureWorkbookTest`
compares the builder's output with the committed `request.json`, so a reorder
fails the build instead of silently renumbering.

- Every formula must return a scalar. An array result would spill over the
  TYPE / ERROR.TYPE / LEN probe columns beside it.
- Formulas reach inputs by address (`Inputs!C2:D5`). The builder binds the same
  addresses as cell-bound inputs in each row's engine request, merging
  overlapping references into one rectangle and sizing an open range (`B:B`) to
  its populated rows.
- holy-sheet writes input floats through `number_format(.., 14)`: keep input
  numbers to 14 decimal places or the workbook holds a different value from the
  request.
- holy-sheet writes real zip mtimes, so the xlsx is not byte-reproducible.
  Rebuild only when cases change, and commit the xlsx and `request.json`
  together; a test checks the digest pairs them.
- Changing a hypothesis or its rows means regenerating SPEC.md section 13's
  table: a test holds it to `request.json`.

## Why not a library

PhpSpreadsheet targets Excel, with no Google Sheets mode; it has no static check
step, no trace of which row matched, no function allowlist with provider
injection, and no step or array budgets; it gives TypeScript and Python no shared
semantics; and its dependency footprint is large for a sandboxed engine. So no
approval request was filed (plan D14), and the grammar is ours.
