<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Audit\ActivityCsv;
use Tests\TestCase;

/**
 * The PHP half of the shared CSV contract.
 *
 * tests/fixtures/csv-contract.json is consumed by this class AND by
 * tests/frontend/unit/csvContract.spec.js. Its expectations derive from the
 * ECMA-262 \s set, so neither implementation gets to define what "correct" is —
 * changing one without the other fails the other language's suite.
 *
 * @internal
 */
class ActivityCsvTest extends TestCase
{
    protected $csv;

    protected $contract;

    protected function setUp(): void
    {
        $this->csv = new ActivityCsv('UTC');
        $this->contract = json_decode(
            file_get_contents(__DIR__.'/../../fixtures/csv-contract.json'),
            true
        );
    }

    // ── Shared contract ──────────────────────────────────────────────────────

    public function testContractVersionMatches()
    {
        $this->assertSame(ActivityCsv::CONTRACT_VERSION, $this->contract['contract_version']);
    }

    public function testColumnsMatchContract()
    {
        $this->assertSame($this->contract['columns'], ActivityCsv::COLUMNS);
    }

    public function testFixtureCarriesAtLeastTheRecordedVectorCounts()
    {
        foreach ($this->contract['counts'] as $section => $count) {
            $this->assertGreaterThanOrEqual(
                $count,
                count($this->contract[$section]),
                "vector section {$section} shrank — deleting a failing vector is not a fix"
            );
        }
    }

    public function testEverySanitizeVector()
    {
        foreach ($this->contract['sanitize'] as $v) {
            $this->assertSame(
                strtolower($v['out_hex']),
                bin2hex($this->csv->sanitizeValue(hex2bin($v['in_hex']))),
                'sanitize vector: '.$v['name']
            );
        }
    }

    public function testEveryFieldVector()
    {
        foreach ($this->contract['field'] as $v) {
            $this->assertSame(
                strtolower($v['out_hex']),
                bin2hex($this->csv->field(hex2bin($v['in_hex']))),
                'field vector: '.$v['name']
            );
        }
    }

    public function testEveryFolderVector()
    {
        foreach ($this->contract['folder_of'] as $v) {
            $this->assertSame($v['out'], $this->csv->folderOf($v['in']), 'folderOf: '.$v['in']);
        }
    }

    public function testEverySortedRowsVector()
    {
        foreach ($this->contract['sorted_rows'] as $v) {
            $this->assertSame(
                array_column($v['out'], 'key'),
                array_column($this->csv->sortedRows($v['counts']), 'key')
            );
        }
    }

    /**
     * Exhaustive over the interesting alphabet, so "spot check" becomes "proof".
     *
     * The comparison is against the whole expected string rather than "output
     * starts with an apostrophe" — the latter is ambiguous when the INPUT
     * starts with one, and reports a false mismatch on U+0027.
     */
    public function testGuardsExactlyTheEcma262WhitespaceSet()
    {
        $wrong = [];
        foreach ($this->contract['codepoints'] as $v) {
            $ch = $v['cp'] < 0x80
                ? chr($v['cp'])
                : mb_convert_encoding('&#'.$v['cp'].';', 'UTF-8', 'HTML-ENTITIES');
            $input = $ch.'=1';
            if (($this->csv->sanitizeValue($input) === "'".$input) !== $v['guarded']) {
                $wrong[] = 'U+'.strtoupper(dechex($v['cp']));
            }
        }
        $this->assertSame([], $wrong);
    }

    // ── PHP-specific hazards the JS side cannot have ─────────────────────────

    /**
     * The reason SIGIL_PATTERN carries no /u modifier.
     *
     * preg_match() with /u returns FALSE (not 0) on malformed UTF-8, and false
     * is falsy — so a /u port silently stops guarding on exactly the inputs
     * most likely to be hostile. This payload is a live DDE formula carrying one
     * trailing invalid byte; under /u it ships unescaped.
     */
    public function testGuardDoesNotFailOpenOnMalformedUtf8()
    {
        $payload = "=cmd|' /c calc'!A1\xFF";

        $this->assertSame("'".$payload, $this->csv->sanitizeValue($payload));
        $this->assertSame(PREG_NO_ERROR, preg_last_error());
    }

    /**
     * PCRE2's UCP \s excludes U+FEFF and includes U+0085, so (*UCP) would be
     * simultaneously weaker than the JS guard on the BOM and broader on NEL.
     */
    public function testBomIsGuardedButNelIsNot()
    {
        $bom = "\xEF\xBB\xBF=1";
        $nel = "\xC2\x85=1";

        $this->assertSame("'".$bom, $this->csv->sanitizeValue($bom));
        $this->assertSame($nel, $this->csv->sanitizeValue($nel));
    }

    /**
     * Sanitise BEFORE quoting: the spreadsheet evaluates the DECODED cell, so
     * the apostrophe has to live inside the quotes. A reordered port produces
     * '"=a,b" and is silently unsafe.
     */
    public function testApostropheGoesInsideTheQuotes()
    {
        $this->assertSame('"\'=a,b"', $this->csv->field('=a,b'));
    }

    /**
     * PHP coerces a numeric-string array key to int, so a user named "123"
     * would change type on the way through a count map. This is PHP's analogue
     * of the __proto__ hazard Reports.spec.js already guards on the JS side.
     */
    public function testNumericStringKeysSurviveAsStrings()
    {
        $rows = $this->csv->sortedRows(['123' => 2, '0123' => 1]);

        $this->assertSame(['123', '0123'], array_column($rows, 'key'));
        foreach ($rows as $row) {
            $this->assertIsString($row['key']);
        }
    }

    public function testHeaderCarriesBomAndCrlf()
    {
        $header = $this->csv->header();

        $this->assertSame(ActivityCsv::BOM, substr($header, 0, 3));
        $this->assertSame("\r\n", substr($header, -2));
        $this->assertSame(ActivityCsv::COLUMNS, explode(',', substr($header, 3, -2)));
    }

    public function testRowOmitsTheSourceIp()
    {
        $row = $this->csv->row([
            'ts' => 1751328000, 'user' => 'alice', 'role' => 'user',
            'action' => 'delete', 'path' => '/clientA/r.pdf',
            'detail' => null, 'ip' => '203.0.113.7',
        ]);

        $this->assertStringNotContainsString('203.0.113.7', $row);
        $this->assertSame(9, count(explode(',', trim($row))));
    }

    public function testFilenameMarksConfidentialAndPartial()
    {
        $from = 1751328000; // 2025-07-01 UTC
        $to = 1753919999;

        $this->assertStringContainsString('CONFIDENTIAL', $this->csv->filename($from, $to));
        $this->assertStringNotContainsString('PARTIAL', $this->csv->filename($from, $to));
        $this->assertStringContainsString('-PARTIAL', $this->csv->filename($from, $to, true));
    }
}
