<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Audit\ReportStore;
use Tests\Fakes\RecordingLogger;
use Tests\TestCase;

/**
 * @internal
 */
class ReportStoreTest extends TestCase
{
    const CSV = "\xEF\xBB\xBFtimestamp_unix,user\r\n1751328000,alice\r\n";

    protected $dir;

    protected $keyPath;

    protected function setUp(): void
    {
        $this->resetTempDir();
        $this->dir = TEST_TMP_PATH.'reports';
        $this->keyPath = TEST_TMP_PATH.'reports_encryption.key';
    }

    protected function tearDown(): void
    {
        $this->resetTempDir();
    }

    protected function makeStore(array $overrides = []): ReportStore
    {
        $store = new ReportStore(new RecordingLogger());
        $store->init(array_merge([
            'dir' => $this->dir,
            'key_path' => $this->keyPath,
            'max_age_days' => 100,
            'max_count' => 24,
        ], $overrides));

        return $store;
    }

    public function testUnconfiguredIsSafeNoOp()
    {
        $store = new ReportStore(new RecordingLogger());

        $this->assertFalse($store->isConfigured());
        $this->assertNull($store->write('2026-07', self::CSV));
        $this->assertSame([], $store->listReports());
        $this->assertNull($store->find('0123456789abcdef0123456789abcdef'));
        $this->assertSame(0, $store->collectGarbage());
    }

    public function testWriteCreatesPrivateDirectoryAndFile()
    {
        $store = $this->makeStore();
        $id = $store->write('2026-07', self::CSV, ['events' => 1]);

        $this->assertNotNull($id);
        clearstatcache(true, $this->dir);
        $this->assertSame('700', substr(sprintf('%o', fileperms($this->dir)), -3));
        $path = $this->dir.'/'.$id.'.csv.enc';
        clearstatcache(true, $path);
        $this->assertSame('600', substr(sprintf('%o', fileperms($path)), -3));
    }

    public function testStoredBytesAreCiphertextNotPlaintext()
    {
        $store = $this->makeStore();
        $id = $store->write('2026-07', self::CSV);

        $raw = file_get_contents($this->dir.'/'.$id.'.csv.enc');

        $this->assertStringStartsWith('v1$', $raw);
        $this->assertStringNotContainsString('timestamp_unix', $raw);
        $this->assertStringNotContainsString('alice', $raw);
    }

    public function testRoundTripIsByteIdenticalIncludingBom()
    {
        $store = $this->makeStore();
        $id = $store->write('2026-07', self::CSV);

        $this->assertSame(self::CSV, $store->readDecrypted($id));
    }

    public function testAtomicWriteLeavesNoTempFile()
    {
        $store = $this->makeStore();
        $store->write('2026-07', self::CSV);

        $this->assertSame([], glob($this->dir.'/*.tmp'));
    }

    public function testFindRejectsUnknownAndMalformedIds()
    {
        $store = $this->makeStore();
        $store->write('2026-07', self::CSV);

        $this->assertNull($store->find('0123456789abcdef0123456789abcdef'));
        $this->assertNull($store->find('../../private/users.json'));
        $this->assertNull($store->find(''));
    }

    public function testIndexedButDeletedFileIsTreatedAsAbsent()
    {
        $store = $this->makeStore();
        $id = $store->write('2026-07', self::CSV);
        unlink($this->dir.'/'.$id.'.csv.enc');

        // Must not present as a downloadable report that then 404s, and must
        // not block regeneration of that period.
        $this->assertNull($store->find($id));
        $this->assertSame([], $store->listReports());
        $this->assertNull($store->findByPeriod('2026-07'));
    }

    /**
     * A key the store cannot open (for instance created root-owned by a cron
     * running as the wrong user) must surface as null, never as an empty body.
     */
    public function testWrongKeyReturnsNullRatherThanThrowing()
    {
        $store = $this->makeStore();
        $id = $store->write('2026-07', self::CSV);

        file_put_contents($this->keyPath, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $other = $this->makeStore();

        $this->assertNull($other->readDecrypted($id));
    }

    public function testTruncatedIndexDegradesToEmptyRatherThanFatal()
    {
        $store = $this->makeStore();
        $store->write('2026-07', self::CSV);

        file_put_contents($this->dir.'/'.ReportStore::INDEX_FILE, '{not valid json');

        $this->assertSame([], $store->listReports());
    }

    public function testGarbageCollectionByAgeUnlinksAndDeindexes()
    {
        $store = $this->makeStore(['max_age_days' => 30]);
        $id = $store->write('2026-07', self::CSV);

        $path = $this->dir.'/'.$id.'.csv.enc';
        $this->assertFileExists($path);

        // 31 days later
        $removed = $store->collectGarbage(time() + (31 * 86400));

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], $store->listReports());
    }

    public function testGarbageCollectionKeepsReportsInsideTheWindow()
    {
        $store = $this->makeStore(['max_age_days' => 30]);
        $store->write('2026-07', self::CSV);

        $this->assertSame(0, $store->collectGarbage(time() + (29 * 86400)));
        $this->assertCount(1, $store->listReports());
    }

    public function testGarbageCollectionTrimsToMaxCountOldestFirst()
    {
        $store = $this->makeStore(['max_count' => 2]);
        foreach (['2026-04', '2026-05', '2026-06'] as $period) {
            $store->write($period, self::CSV);
            // generated_at has one-second resolution; keep the order unambiguous
            usleep(1100000);
        }

        $store->collectGarbage();
        $periods = array_column($store->listReports(), 'period');

        $this->assertCount(2, $periods);
        $this->assertNotContains('2026-04', $periods);
    }

    public function testListReportsCarriesMetadataButNoEventData()
    {
        $store = $this->makeStore();
        $store->write('2026-07', self::CSV, ['events' => 42, 'coverage' => 'complete']);

        $rows = $store->listReports();

        $this->assertCount(1, $rows);
        $this->assertSame('2026-07', $rows[0]['period']);
        $this->assertSame(42, $rows[0]['events']);
        $this->assertSame(strlen(self::CSV), $rows[0]['bytes']);
        // Nothing derived from the CSV body may appear in the listing.
        $this->assertStringNotContainsString('alice', json_encode($rows));
    }

    /**
     * The documented 0700 defence exists because the project's own install
     * steps run `chmod -R 775`. Applying it only at creation meant the
     * protection lasted until the next time someone followed those steps.
     */
    public function testDirectoryModeIsRepairedNotOnlySetAtCreation()
    {
        $store = $this->makeStore();
        $store->write('2026-07', self::CSV);

        chmod($this->dir, 0775);
        clearstatcache(true, $this->dir);
        $this->assertSame('775', substr(sprintf('%o', fileperms($this->dir)), -3));

        // Any subsequent write must narrow it back.
        $store->write('2026-08', self::CSV);

        clearstatcache(true, $this->dir);
        $this->assertSame('700', substr(sprintf('%o', fileperms($this->dir)), -3));
    }

    public function testIdsAreUnguessableRatherThanThePeriod()
    {
        $store = $this->makeStore();
        $id = $store->write('2026-07', self::CSV);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertStringNotContainsString('2026', $id);
    }
}
