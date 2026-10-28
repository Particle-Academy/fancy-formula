<?php

declare(strict_types=1);

use FancyFormula\Capture\CaptureBuilder;
use FancyFormula\Tests\Support\SchemaValidator;

require_once dirname(__DIR__, 2).'/scripts/capture/CaptureBuilder.php';

/*
 * The capture workbook is the input to the ONLY golden source this engine has
 * (D3): Google Sheets. Everything here guards against the capture being
 * quietly wrong before anyone uploads it -- a stale request.json, a formula
 * that went in WITH a value (so Sheets never computed it), a text input that
 * holy-sheet promoted to a formula, or a SPEC hypothesis citing a row that
 * does not exist.
 */

function captureDir(): string
{
    return dirname(__DIR__, 2).'/capture/v1';
}

/** @return array<string, mixed> */
function committedRequest(): array
{
    return json_decode((string) file_get_contents(captureDir().'/request.json'), true, 512, JSON_THROW_ON_ERROR);
}

function builder(): CaptureBuilder
{
    return new CaptureBuilder(require dirname(__DIR__, 2).'/scripts/capture/cases-v1.php');
}

/** @return array<string, string> worksheet XML by sheet name */
function workbookSheets(): array
{
    $zip = new ZipArchive;
    expect($zip->open(captureDir().'/capture-v1.xlsx'))->toBeTrue();
    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
    preg_match_all('/<sheet name="([^"]+)" sheetId="\d+" r:id="(rId\d+)"/', $workbook, $sheets, PREG_SET_ORDER);
    $out = [];
    foreach ($sheets as [, $name, $rid]) {
        preg_match('/Id="'.$rid.'"[^>]*Target="([^"]+)"|Target="([^"]+)"[^>]*Id="'.$rid.'"/', $rels, $t);
        $target = ltrim($t[1] !== '' ? $t[1] : $t[2], '/');
        $path = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        $out[$name] = (string) $zip->getFromName($path);
    }
    $zip->close();

    return $out;
}

test('request.json is exactly what the builder produces from cases-v1.php', function () {
    $built = json_decode(json_encode(builder()->request('v1'), JSON_THROW_ON_ERROR), true);
    $committed = committedRequest();
    unset($committed['workbookSha256']);

    // A reorder or an inserted row renumbers ids that SPEC.md and the schemas
    // cite; this is where that becomes visible instead of silent.
    expect(array_column($committed['cases'], 'id'))->toBe(array_column($built['cases'], 'id'));
    expect($committed)->toBe($built);
});

test('the committed workbook is the one request.json describes', function () {
    expect(hash_file('sha256', captureDir().'/capture-v1.xlsx'))->toBe(committedRequest()['workbookSha256']);
});

test('every formula cell in the workbook has NO cached value, so Sheets computes all of them', function () {
    $sheets = workbookSheets();
    preg_match_all('/<c r="([A-Z]+)(\d+)"[^>]*>(.*?)<\/c>/s', $sheets['Cases'], $cells, PREG_SET_ORDER);
    $formulas = 0;
    foreach ($cells as [, $col, $row, $body]) {
        if (str_contains($body, '<f>')) {
            $formulas++;
            expect(str_contains($body, '<v>'))->toBeFalse("Cases!{$col}{$row} carries a cached value");
        }
    }
    expect($formulas)->toBe(4 * count(committedRequest()['cases']));
});

test('the Inputs sheet holds no formulas: text that looks like one went in as a typed string', function () {
    $inputs = workbookSheets()['Inputs'];
    expect(substr_count($inputs, '<f>'))->toBe(0);
    expect(str_contains($inputs, '<t xml:space="preserve">=1+1</t>'))->toBeTrue('the "=1+1" text input is missing or was not written as text');
});

test('the Cases sheet carries every case id and formula, in order, on the row request.json names', function () {
    $sheet = workbookSheets()['Cases'];
    $cases = committedRequest()['cases'];
    foreach ($cases as $case) {
        $n = $case['row'];
        expect(preg_match('/<c r="A'.$n.'"[^>]*><is><t xml:space="preserve">([^<]*)<\/t><\/is><\/c>/', $sheet, $id))->toBe(1, "no id on row {$n}");
        expect($id[1])->toBe($case['id']);
        expect(preg_match('/<c r="B'.$n.'"[^>]*><f>(.*?)<\/f><\/c>/s', $sheet, $f))->toBe(1, "no formula on row {$n}");
        expect('='.html_entity_decode($f[1], ENT_QUOTES | ENT_XML1, 'UTF-8'))->toBe($case['formula']);
    }
});

test('case ids are unique, well formed, and the canaries come first', function () {
    $ids = array_column(committedRequest()['cases'], 'id');
    expect(count($ids))->toBe(count(array_unique($ids)));
    foreach ($ids as $id) {
        expect(preg_match('/^[KPCFDLSEA]-\d{4}$/', $id))->toBe(1, "bad id {$id}");
    }
    expect(array_slice($ids, 0, 2))->toBe(['K-0001', 'K-0002']);
    $canaries = array_column(committedRequest()['canaries'], 'formula', 'id');
    $byId = array_column(committedRequest()['cases'], 'formula', 'id');
    foreach ($canaries as $id => $formula) {
        expect($byId[$id])->toBe($formula);
    }
});

test('every row carries an engine request that satisfies the request schema', function () {
    $v = new SchemaValidator(dirname(__DIR__, 2).'/schema');
    foreach (committedRequest()['cases'] as $case) {
        $instance = SchemaValidator::decode(json_encode($case['request'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        // json_decode(..., true) turned the empty output declaration into []; restore {}.
        $instance->outputs->result = new stdClass;
        $errors = $v->validate($instance, 'request.schema.json');
        expect($errors)->toBe([], "{$case['id']}: ".implode('; ', $errors));
        expect($case['request']['formulas']['result'])->toBe($case['formula']);
    }
});

test('every formula fits the default limits the engine will enforce', function () {
    foreach (committedRequest()['cases'] as $case) {
        expect(strlen($case['formula']))->toBeLessThanOrEqual(8192, $case['id']);
    }
});

test('no formula returns a bare array, which would spill over the probe columns', function () {
    foreach (committedRequest()['cases'] as $case) {
        $f = $case['formula'];
        expect(preg_match('/^=\s*\{/', $f))->toBe(0, "{$case['id']} is a bare array literal");
        expect(preg_match('/^=\s*Inputs!\$?[A-Z]+\$?\d*:\$?[A-Z]+\$?\d*$/', $f))->toBe(0, "{$case['id']} is a bare range");
    }
});

test('every captured P1 function has at least three rows of its own', function () {
    $p1 = require dirname(__DIR__, 2).'/scripts/capture/p1-functions.php';
    $count = [];
    foreach (committedRequest()['cases'] as $case) {
        if (isset($case['fn'])) {
            $count[$case['fn']] = ($count[$case['fn']] ?? 0) + 1;
            expect(in_array($case['fn'], $case['functions'], true))->toBeTrue("{$case['id']} is filed under {$case['fn']} but does not call it");
        }
    }
    foreach ($p1 as $fn => $suite) {
        if ($suite === null) {
            expect($count[$fn] ?? 0)->toBe(0, "{$fn} is volatile and cannot be captured");

            continue;
        }
        expect($count[$fn] ?? 0)->toBeGreaterThanOrEqual(3, "{$fn} has too few rows");
    }
});

test('Appendix A: all eight cases are captured, and A and H carry every output', function () {
    $byCase = [];
    foreach (committedRequest()['cases'] as $case) {
        if (str_starts_with($case['id'], 'A-')) {
            $byCase[$case['case']][] = $case['output'];
        }
    }
    expect(array_keys($byCase))->toBe(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']);
    expect(count($byCase['A']))->toBe(15);
    expect(count($byCase['H']))->toBe(15);
});

test('every hypothesis is decided by at least one row', function () {
    foreach (committedRequest()['hypotheses'] as $h => $entry) {
        expect($entry['rows'])->not->toBe([], "{$h} has no deciding row");
    }
});

test('SPEC.md lists every hypothesis with exactly the rows that decide it', function () {
    $spec = (string) file_get_contents(dirname(__DIR__, 2).'/SPEC.md');
    $hypotheses = committedRequest()['hypotheses'];
    // \r? because this estate checks out with core.autocrlf=true.
    preg_match_all('/^\| (H-\d{2}) \|.*\| ([A-Z]-\d{4}(?:, [A-Z]-\d{4})*) \|\r?$/m', $spec, $m, PREG_SET_ORDER);
    $listed = [];
    foreach ($m as [, $h, $rows]) {
        $listed[$h] = explode(', ', $rows);
    }
    ksort($listed);
    $want = array_map(static fn (array $e): array => $e['rows'], $hypotheses);
    ksort($want);
    expect($listed)->toBe($want);
});

test('every capture id cited in SPEC.md or a schema exists', function () {
    $ids = array_flip(array_column(committedRequest()['cases'], 'id'));
    $root = dirname(__DIR__, 2);
    $texts = [file_get_contents($root.'/SPEC.md')];
    foreach (glob($root.'/schema/*.json') as $file) {
        $texts[] = file_get_contents($file);
    }
    $cited = 0;
    foreach ($texts as $text) {
        preg_match_all('/\b([KPCFDLSEA]-\d{4})\b/', (string) $text, $m);
        foreach ($m[1] as $id) {
            $cited++;
            expect(isset($ids[$id]))->toBeTrue("{$id} is cited but is not a capture row");
        }
    }
    expect($cited)->toBeGreaterThan(0);
});

test('the builder binds overlapping references once, and open ranges to their populated extent', function () {
    $cases = array_column(committedRequest()['cases'], null, 'formula');
    expect(array_keys($cases['=ROWS(Inputs!B:B)']['request']['inputs']))->toBe(['Inputs!B1:B5']);
    expect(array_keys($cases['=COUNTIF(Inputs!P1:P4,Inputs!P2)']['request']['inputs']))->toBe(['Inputs!P1:P4']);
    expect($cases['=Inputs!J1']['request']['inputs'])->toBe(['Inputs!J1' => ['type' => 'text', 'value' => '=1+1']]);
    expect($cases['=1+Inputs!O1']['request']['inputs'])->toBe(['Inputs!O1' => ['type' => 'range', 'rows' => [[null]]]]);
});

test('functions() ignores names inside string literals', function () {
    expect(CaptureBuilder::functions('=IF(A1="SUM(x)",LEN("ROUND("),max(1,2))'))->toBe(['IF', 'LEN', 'MAX']);
});
