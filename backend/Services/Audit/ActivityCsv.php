<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Audit;

/**
 * Serialises audit events to the activity-report CSV.
 *
 * This is the server-side twin of the CSV builder in frontend/views/Reports.vue.
 * Two producers of one format is a drift hazard, so the shared vector fixture at
 * tests/fixtures/csv-contract.json is exercised by BOTH suites and both sides
 * assert CONTRACT_VERSION. Change the format here and the Jest contract spec
 * fails until the JS is changed too, and vice versa.
 *
 * Deliberately pure: no I/O, no clock, no container. The timezone is a
 * constructor argument rather than a global read so the class stays trivially
 * unit- and mutation-testable.
 *
 * The `ip` field is recorded in the log but is NOT a column here. It has never
 * been rendered in the UI, and the export must not be the thing that first
 * persists it outside private/.
 */
class ActivityCsv
{
    /**
     * Bumped whenever the emitted format changes. Duplicated in Reports.vue and
     * asserted by both suites against the fixture — that duplication is the
     * forcing function that makes a human look at the other implementation.
     */
    const CONTRACT_VERSION = 1;

    const COLUMNS = [
        'timestamp_unix',
        'timestamp_iso',
        'timestamp_local',
        'user',
        'role',
        'action',
        'path',
        'folder',
        'detail',
    ];

    const BOM = "\xEF\xBB\xBF";

    const EOL = "\r\n";

    /**
     * Spreadsheet formula injection: a cell whose first meaningful character is
     * = + - or @ is evaluated as a formula by Excel / LibreOffice / Sheets, so a
     * crafted value becomes code execution on the machine that opens the export.
     * Leading whitespace (and a BOM or NUL) is stripped by the spreadsheet
     * BEFORE it looks for the sigil, so the guard has to skip it too.
     *
     * This enumerates the UTF-8 encodings of exactly JavaScript's \s set
     * (WhiteSpace + LineTerminator per ECMA-262: U+0009-U+000D, U+0020, U+00A0,
     * U+1680, U+2000-U+200A, U+2028, U+2029, U+202F, U+205F, U+3000, U+FEFF)
     * plus NUL, so it matches Reports.vue's /^[\s\u0000]*[=+\-@]/ byte for byte.
     *
     * Two things it deliberately is NOT:
     *
     * - NOT (*UCP). PCRE2's Unicode \s is White_Space, which EXCLUDES U+FEFF
     *   (dropped in Unicode 4.0.1) and INCLUDES U+0085 NEL — simultaneously
     *   weaker than the JS guard on the BOM and broader on NEL.
     * - NOT /u. preg_match() returns false, not 0, on malformed UTF-8
     *   (PREG_BAD_UTF8_ERROR), and false is falsy — so a /u pattern silently
     *   stops guarding on exactly the inputs most likely to be hostile. A live
     *   DDE payload carrying one trailing invalid byte gets through unescaped.
     *   A byte pattern cannot enter that state at all.
     */
    const SIGIL_PATTERN = '/^(?:[\x00\x09-\x0D\x20]|\xC2\xA0|\xE1\x9A\x80'
                        .'|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F'
                        .'|\xE3\x80\x80|\xEF\xBB\xBF)*[=+\-@]/';

    /** Cells needing RFC 4180 quoting. Newlines matter as much as commas: a
     *  POSIX filename may legally contain one, and while the backend's
     *  json_encode stops a crafted name forging a LOG line, nothing stops it
     *  splitting a CSV ROW. */
    const QUOTE_PATTERN = '/["\n\r,]/';

    protected $timezone;

    public function __construct(string $timezone = 'UTC')
    {
        $this->timezone = new \DateTimeZone($timezone);
    }

    /**
     * Neutralise a leading formula sigil by prefixing an apostrophe.
     *
     * Note the `!== 0` rather than a truthiness test: if this pattern is ever
     * changed to one that can error, "errored" must mean "sanitise", never
     * "pass through untouched".
     */
    public function sanitizeValue($value): string
    {
        $s = $value === null ? '' : (string) $value;

        return preg_match(self::SIGIL_PATTERN, $s) !== 0 ? "'".$s : $s;
    }

    /**
     * RFC 4180 field. Sanitises BEFORE quoting, because the spreadsheet
     * evaluates the DECODED cell — "'=1+1" inside quotes still decodes to
     * '=1+1, which no engine treats as a formula. Prefixing also never
     * introduces a CSV metacharacter, so it cannot invalidate the quoting
     * decision made after. The ordering is load-bearing and is pinned by a
     * fixture vector.
     */
    public function field($value): string
    {
        $s = $this->sanitizeValue($value);

        return preg_match(self::QUOTE_PATTERN, $s) !== 0
            ? '"'.str_replace('"', '""', $s).'"'
            : $s;
    }

    /**
     * Immediate parent directory of a root-relative audit path. Grouping at the
     * parent (rather than the top-level segment) keeps the rollup lossless.
     */
    public function folderOf($path): string
    {
        $p = is_string($path) ? $path : '';
        if ($p === '') {
            return '/';
        }
        $i = strrpos($p, '/');

        return ($i === false || $i === 0) ? '/' : substr($p, 0, $i);
    }

    /**
     * Count map to rows, ordered count DESC then key ASC.
     *
     * Not a locale-aware compare: Reports.vue used localeCompare, which orders
     * ['a','B','_','C'] as ['_','a','B','C'] where a byte compare gives
     * ['B','C','_','a']. Both sides now use a code-unit comparison, so row
     * order no longer depends on ICU.
     *
     * Ties are broken on UTF-16BE bytes rather than raw strcmp, because
     * JavaScript's `<` compares UTF-16 CODE UNITS while strcmp compares UTF-8
     * bytes (i.e. code-point order). Those agree across the whole BMP and
     * diverge above U+FFFF, where a surrogate pair leads with 0xD800-0xDBFF and
     * therefore sorts BEFORE U+E000-U+FFFF in JS but after them in PHP.
     * Measured: ['\u{FF21}', '\u{1D400}'] sorts astral-first in JS and
     * astral-last under strcmp. Converting to UTF-16BE first makes the two
     * genuinely identical over all inputs, which is what the shared fixture
     * asserts.
     */
    public function sortedRows(array $counts): array
    {
        $rows = [];
        foreach ($counts as $key => $count) {
            // PHP coerces a numeric-string array key to int, so a user named
            // "123" would come back as an int and change type on the way out.
            $rows[] = ['key' => (string) $key, 'count' => $count];
        }

        usort($rows, function ($a, $b) {
            return ($b['count'] <=> $a['count'])
                ?: strcmp($this->utf16SortKey($a['key']), $this->utf16SortKey($b['key']));
        });

        return $rows;
    }

    /**
     * UTF-16BE encoding of a key, for JS-identical ordering.
     *
     * Falls back to the raw bytes if the value is not valid UTF-8 — invalid
     * input should still sort deterministically rather than throw, and audit
     * usernames reaching here are already valid by the time they are stored.
     */
    protected function utf16SortKey(string $key): string
    {
        $converted = @mb_convert_encoding($key, 'UTF-16BE', 'UTF-8');

        return $converted === false ? $key : $converted;
    }

    public function header(): string
    {
        return self::BOM.implode(',', self::COLUMNS).self::EOL;
    }

    public function row(array $event): string
    {
        $ts = isset($event['ts']) ? (int) $event['ts'] : 0;
        $path = isset($event['path']) ? $event['path'] : '';

        $cells = [
            $ts,
            gmdate('Y-m-d\TH:i:s\Z', $ts),
            $this->localTimestamp($ts),
            isset($event['user']) ? $event['user'] : '',
            isset($event['role']) ? $event['role'] : '',
            isset($event['action']) ? $event['action'] : '',
            $path,
            $this->folderOf($path),
            isset($event['detail']) ? $event['detail'] : '',
        ];

        return implode(',', array_map([$this, 'field'], $cells)).self::EOL;
    }

    /**
     * Column 3 in the server's configured timezone.
     *
     * The browser renders this column with moment using frontend `date_format`
     * (a moment token string such as 'MM/DD/YY hh:mm:ss' — those tokens mean
     * entirely different things to PHP's date(), so it cannot simply be reused).
     * A cron has neither a browser timezone nor that setting, so this emits a
     * fixed ISO-with-offset instead. Consequence, documented in reports.md: for
     * the same event, a cron export and a browser export differ in column 3.
     * Columns 1 and 2 are timezone-independent and remain the join keys.
     */
    protected function localTimestamp(int $ts): string
    {
        return (new \DateTimeImmutable('@'.$ts))
            ->setTimezone($this->timezone)
            ->format('Y-m-d H:i:sP');
    }

    /**
     * @param array $events newest-first, as returned by AuditLog::query()
     */
    public function build(array $events): string
    {
        $out = $this->header();
        foreach ($events as $event) {
            $out .= $this->row($event);
        }

        return $out;
    }

    /**
     * CONFIDENTIAL is in the name on purpose: the file leaves the app's control
     * the moment it is downloaded, and the marker travels with it if it gets
     * forwarded. PARTIAL likewise — the filename is the part that survives being
     * forwarded, so an incomplete month must say so there and not only in
     * metadata the recipient never sees.
     */
    public function filename(int $from, int $to, bool $partial = false): string
    {
        $stamp = function (int $ts) {
            return (new \DateTimeImmutable('@'.$ts))->setTimezone($this->timezone)->format('Y-m-d');
        };

        return 'filegator-activity-CONFIDENTIAL-'
            .$stamp($from).'-to-'.$stamp($to)
            .($partial ? '-PARTIAL' : '')
            .'.csv';
    }
}
