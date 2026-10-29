<?php

declare(strict_types=1);

use FancyFormula\Tests\Support\Goldens;
use ParticleAcademy\Conformance\Conformance;

/*
 * The Google Sheets goldens: fancy-conformance's `formula/*` suites, captured
 * from Google Sheets (the only golden source, D3) and run against the engine.
 *
 * A row is skipped ONLY when it needs a function or a TEXT format code outside
 * slice 1, decided statically from the formula, with a reason naming slice 2.
 * Every skip is printed by id. Rows in Goldens::EXPORT_PRECISION are run and
 * asserted at the precision the export really kept.
 *
 * The pin is EXACT, as in fancy-expr: composer.lock is not committed, so the
 * require-dev constraint is the only thing CI resolves from, and a range would
 * mean "whatever is newest the day CI runs". Move it only after re-running
 * every suite here against the new version.
 */
const FORMULA_SUITE_VERSION = '0.39.0';

it('runs against the pinned fixture set, and says which', function (): void {
    echo "\nfancy-conformance installed: ".Conformance::version().', pinned: '.FORMULA_SUITE_VERSION."\n";

    expect(Conformance::version())->toBe(FORMULA_SUITE_VERSION);

    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($composer['require-dev']['particle-academy/fancy-conformance'] ?? null)->toBe(FORMULA_SUITE_VERSION);
});

it('runs every formula suite the fixture set has', function (): void {
    $present = array_values(array_filter(
        Conformance::listSuites(),
        static fn (string $s): bool => str_starts_with($s, 'formula/'),
    ));
    sort($present);
    $ours = Goldens::SUITES;
    sort($ours);
    expect($ours)->toBe($present);
});

it('matches the Google Sheets goldens in', function (string $suite): void {
    $r = Goldens::suite($suite);

    echo "\n{$suite}: {$r['passed']} passed, ".count($r['failed']).' failed, '.count($r['skipped'])." skipped\n";
    foreach ($r['skipped'] as $skip) {
        echo "  skip {$skip['id']}: {$skip['reason']}\n";
    }
    foreach ($r['skipped'] as $skip) {
        expect($skip['reason'])->toStartWith('slice 2: ');
    }

    expect($r['failed'])->toBe([], "{$suite} disagrees with Google Sheets on: ".implode(', ', array_column($r['failed'], 'id')));
    // The vacuity floor: a suite that ran nothing reports no failures too.
    expect($r['passed'])->toBeGreaterThan(0);
})->with(Goldens::SUITES);

it('runs at least 482 slice-1 golden rows in all, and every row is accounted for', function (): void {
    $passed = 0;
    $skipped = 0;
    $rows = 0;
    foreach (Goldens::SUITES as $suite) {
        $rows += count(Conformance::cases($suite));
        $r = Goldens::suite($suite);
        $passed += $r['passed'];
        $skipped += count($r['skipped']);
    }
    expect($rows)->toBe(687)
        ->and($passed + $skipped)->toBe($rows)
        ->and($passed)->toBeGreaterThanOrEqual(482);
});

it('names only export-precision rows that exist, and nothing else', function (): void {
    $ids = [];
    foreach (Goldens::SUITES as $suite) {
        foreach (Conformance::cases($suite) as $case) {
            $ids[] = $case['tags'][0];
        }
    }
    foreach (array_keys(Goldens::EXPORT_PRECISION) as $id) {
        expect($ids)->toContain($id);
    }
    expect(count(Goldens::EXPORT_PRECISION))->toBe(4);
});
