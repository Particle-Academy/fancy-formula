<?php

declare(strict_types=1);

/*
 * Write the capture workbook and its request.json.
 *
 *     php scripts/build-capture-workbook.php            # capture/v1/
 *     php scripts/build-capture-workbook.php --out DIR
 *
 * The workbook is written by holy-sheet (first party, a dev dependency). Every
 * formula cell goes in WITHOUT a cached value, so every value in the export
 * comes from Google Sheets. request.json lists every case id, its formula, the
 * engine request the conformance row will carry, and which hypotheses it
 * decides; it records the workbook's sha256, so a capture can be matched to the
 * exact file that was uploaded.
 *
 * The xlsx is not byte-reproducible (holy-sheet writes real zip mtimes), so a
 * rebuild changes the digest. Rebuild only when the cases change, and commit
 * the pair together.
 */

use FancyFormula\Capture\CaptureBuilder;
use HolySheet\Agent;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/capture/CaptureBuilder.php';

$version = 'v1';
$out = dirname(__DIR__)."/capture/{$version}";
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--out' && isset($args[$i + 1])) {
        $out = $args[++$i];
    } else {
        fwrite(STDERR, "Unknown argument {$args[$i]}\n");
        exit(2);
    }
}

$builder = new CaptureBuilder(require __DIR__."/capture/cases-{$version}.php");

if (! is_dir($out) && ! mkdir($out, 0777, true)) {
    fwrite(STDERR, "Cannot create {$out}\n");
    exit(1);
}

$xlsx = "{$out}/capture-{$version}.xlsx";
Agent::write($builder->workbook(), $xlsx);

$request = $builder->request($version);
$request = ['$comment' => $request['$comment'], 'capture' => $request['capture'], 'workbook' => $request['workbook'],
    'workbookSha256' => hash_file('sha256', $xlsx)] + $request;

file_put_contents(
    "{$out}/request.json",
    json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
);

$bySuite = [];
foreach ($request['cases'] as $case) {
    $bySuite[$case['suite'] ?? '(canary)'] = ($bySuite[$case['suite'] ?? '(canary)'] ?? 0) + 1;
}
ksort($bySuite);
printf("Wrote %s (%d rows) and request.json\n", $xlsx, count($request['cases']));
foreach ($bySuite as $suite => $n) {
    printf("  %-26s %4d\n", $suite, $n);
}
