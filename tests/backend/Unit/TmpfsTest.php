<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Tmpfs\Adapters\Tmpfs;
use Tests\TestCase;

/**
 * @internal
 */
class TmpfsTest extends TestCase
{
    protected $service;

    protected function setUp(): void
    {
        $this->resetTempDir();
        rmdir(TEST_TMP_PATH);

        $this->service = new Tmpfs();
        $this->service->init([
            'path' => TEST_TMP_PATH,
            'gc_probability_perc' => 100,
            'gc_older_than' => 60 * 60 * 24 * 2, // 2 days
        ]);

        parent::setUp();
    }

    public function testWriteContentToTmpFile()
    {
        $this->service->write('a.txt', 'lorem');

        $this->assertFileExists(TEST_TMP_PATH.'/a.txt');
    }

    public function testWriteContentToTmpFileUsingStream()
    {
        $stream = fopen(TEST_FILE, 'r');
        $this->service->write('a.txt', $stream);

        $this->assertFileEquals(TEST_TMP_PATH.'/a.txt', TEST_FILE);
    }

    public function testReadingTmpFileContents()
    {
        $this->service->write('a.txt', 'lorem');

        $contents = $this->service->read('a.txt');

        $this->assertEquals('lorem', $contents);
    }

    public function testReadingTmpFileContentsUsingStream()
    {
        $this->service->write('a.txt', 'lorem');

        $ret = $this->service->readStream('a.txt');
        $this->assertEquals('a.txt', $ret['filename']);

        $contents = stream_get_contents($ret['stream']);
        $this->assertEquals('lorem', $contents);
    }

    public function testRemovingTmpFile()
    {
        $this->service->write('a.txt', 'lorem');

        $this->assertFileExists(TEST_TMP_PATH.'a.txt');

        $this->service->remove('a.txt');

        $this->assertFileNotExists(TEST_TMP_PATH.'a.txt');
    }

    public function testCheckExistingFile()
    {
        $this->service->write('a.txt', 'lorem');

        $this->assertTrue($this->service->exists('a.txt'));
        $this->assertFalse($this->service->exists('nothere.txt'));
    }

    public function testFindingAllFilesMatchingPatters()
    {
        $this->service->write('a.txt', 'lorem');
        $this->service->write('b.txt', 'lorem');
        $this->service->write('b.zip', 'lorem');

        $this->assertCount(2, $this->service->findAll('*.txt'));
        $this->assertCount(2, $this->service->findAll('b*'));
        $this->assertCount(3, $this->service->findAll('*'));
        $this->assertCount(0, $this->service->findAll('1*2'));
    }

    public function testCleaningFilesOlderThan()
    {
        $this->service->write('a.txt', 'lorem');
        $this->service->write('b.txt', 'lorem');
        $this->service->write('b.zip', 'lorem');

        $this->service->clean(10000);

        $this->assertCount(3, $this->service->findAll('*'));

        $this->service->clean(0);

        $this->assertCount(0, $this->service->findAll('*'));
    }

    public function testDeleteOldFilesAutomaticaly()
    {
        touch(TEST_TMP_PATH.'fresh.txt', time());
        touch(TEST_TMP_PATH.'old.txt', time() - 60 * 60 * 24 * 10); // 10 days old

        $this->service->init([
            'path' => TEST_TMP_PATH,
            'gc_probability_perc' => 100,
            'gc_older_than' => 60 * 60 * 24 * 2, // 2 days
        ]);

        $this->assertFileExists(TEST_TMP_PATH.'fresh.txt');
        $this->assertFileNotExists(TEST_TMP_PATH.'old.txt');
    }

    public function testGarbageIsNotDeletedEveryTime()
    {
        touch(TEST_TMP_PATH.'old.txt', time() - 60 * 60 * 24 * 10); // 10 days old

        $this->service->init([
            'path' => TEST_TMP_PATH,
            'gc_probability_perc' => 0,
            'gc_older_than' => 60 * 60 * 24 * 2, // 2 days
        ]);

        $this->assertFileExists(TEST_TMP_PATH.'old.txt');
    }

    public function testAddIfAbsentCreatesOnceAndRejectsDuplicate()
    {
        // First caller wins (file did not exist) and the payload is written.
        $this->assertTrue($this->service->addIfAbsent('marker', 'first'));
        $this->assertEquals('first', $this->service->read('marker'));

        // Second caller observes the existing marker and is rejected; the
        // original payload is left untouched (no clobber of the winner).
        $this->assertFalse($this->service->addIfAbsent('marker', 'second'));
        $this->assertEquals('first', $this->service->read('marker'));

        // A different key is unaffected by the first marker.
        $this->assertTrue($this->service->addIfAbsent('other'));
    }

    public function testAddIfAbsentDefaultsToSentinelByte()
    {
        $this->assertTrue($this->service->addIfAbsent('flag'));
        $this->assertEquals('1', $this->service->read('flag'));
    }

    public function testAddIfAbsentFailsClosedWhenPathUnopenable()
    {
        // Point the adapter at a directory that does not exist so fopen('x')
        // cannot create the marker. The replay-guard contract is fail-closed:
        // an un-creatable marker must report false, never silently "absent".
        $this->setServicePath(TEST_TMP_PATH.'missing_subdir/');

        $this->assertFalse($this->service->addIfAbsent('marker'));
    }

    public function testIncrementCounterIfBelowCountsUpThenCapsAtMax()
    {
        // Each successful call returns the new count and grows the file by one
        // byte; once the cap is reached every further call fails closed (-1).
        $this->assertSame(1, $this->service->incrementCounterIfBelow('c', 3));
        $this->assertSame(1, strlen($this->service->read('c')));

        $this->assertSame(2, $this->service->incrementCounterIfBelow('c', 3));
        $this->assertSame(3, $this->service->incrementCounterIfBelow('c', 3));
        $this->assertSame(3, strlen($this->service->read('c')));

        // At the cap: rejected, and the counter is NOT advanced past max.
        $this->assertSame(-1, $this->service->incrementCounterIfBelow('c', 3));
        $this->assertSame(-1, $this->service->incrementCounterIfBelow('c', 3));
        $this->assertSame(3, strlen($this->service->read('c')));
    }

    public function testIncrementCounterIfBelowRejectsWhenMaxIsZero()
    {
        $this->assertSame(-1, $this->service->incrementCounterIfBelow('z', 0));
    }

    public function testIncrementCounterIfBelowFailsClosedWhenPathUnopenable()
    {
        // Un-openable counter file must fail closed (limit-exceeded), never
        // grant an increment — otherwise a throttle silently disables itself.
        $this->setServicePath(TEST_TMP_PATH.'missing_subdir/');

        $this->assertSame(-1, $this->service->incrementCounterIfBelow('c', 5));
    }

    /**
     * Repoint the adapter's storage directory without re-running init() (which
     * would create the directory). Used to exercise the fail-closed branches.
     */
    private function setServicePath(string $path): void
    {
        $ref = new \ReflectionProperty(Tmpfs::class, 'path');
        $ref->setAccessible(true);
        $ref->setValue($this->service, $path);
    }

    public function testSanitizeFilename()
    {
        // regular
        $this->assertEquals('test.txt', $this->invokeMethod($this->service, 'sanitizeFilename', ['test.txt']));

        // utf-8
        $this->assertEquals('ąčęėįšųū.txt', $this->invokeMethod($this->service, 'sanitizeFilename', ['ąčęėįšųū.txt']));

        // multi-byte
        $this->assertEquals('断及服务层流.txt', $this->invokeMethod($this->service, 'sanitizeFilename', ['断及服务层流.txt']));

        // with invalid chars
        $this->assertEquals('..--s-u---pe----rm---an-.t-xt..--', $this->invokeMethod($this->service, 'sanitizeFilename', ["../\\s\"u<:>pe////rm?*|an\\.t\txt../;"]));

        // oversized
        $this->assertEquals(
                '123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345',
            $this->invokeMethod($this->service, 'sanitizeFilename', [
                '1234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456'
            ]));
    }
}
