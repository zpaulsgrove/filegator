<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Archiver\Adapters\ZipArchiver;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Tmpfs\Adapters\Tmpfs;
use League\Flysystem\Memory\MemoryAdapter;
use League\Flysystem\Adapter\NullAdapter;
use Tests\TestCase;

/**
 * @internal
 */
class ArchiverTest extends TestCase
{
    protected $archiver;

    protected function setUp(): void
    {
        $tmpfs = new Tmpfs();
        $tmpfs->init([
            'path' => TEST_TMP_PATH,
            'gc_probability_perc' => 10,
            'gc_older_than' => 60 * 60 * 24 * 2, // 2 days
        ]);

        $this->archiver = new ZipArchiver($tmpfs);

        parent::setUp();
    }

    public function testCreatingEmptyArchive()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new NullAdapter();
            },
        ]);

        $uniqid = $this->archiver->createArchive($storage);

        $this->assertNotNull($uniqid);
        $this->assertFileDoesNotExist(TEST_TMP_PATH.$uniqid);
    }

    public function testAddingDirectoryWithFilesAndSubdir()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new MemoryAdapter();
            },
        ]);

        $storage->createDir('/', 'test');
        $storage->createDir('/test', 'sub');
        $storage->createFile('/test', 'file1.txt');
        $storage->createFile('/test', 'file2.txt');

        $name = $this->archiver->createArchive($storage);
        $this->archiver->addDirectoryFromStorage('/test');
        $this->archiver->closeArchive();

        $this->assertGreaterThan(0, filesize(TEST_TMP_PATH.$name));
    }

    /**
     * Read the entry names out of the just-built (closed) archive in tmpfs.
     */
    protected function archiveEntries(string $name): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(TEST_TMP_PATH.$name) === true, 'archive should open');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $entries;
    }

    public function testSingleFileIsStoredAtArchiveRoot()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new MemoryAdapter();
            },
        ]);

        $storage->createDir('/', 'clientA');
        $storage->createDir('/clientA', '2023');
        $storage->createFile('/clientA/2023', 'return.pdf');

        $name = $this->archiver->createArchive($storage);
        $this->archiver->addFileFromStorage('/clientA/2023/return.pdf');
        $this->archiver->closeArchive();

        // No nested folder tree: the file sits at the archive root, not under
        // clientA/2023/.
        $this->assertEquals(['return.pdf'], $this->archiveEntries($name));
    }

    public function testDirectoryIsStoredUnderItsOwnNameNotFullPath()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new MemoryAdapter();
            },
        ]);

        $storage->createDir('/', 'clientA');
        $storage->createDir('/clientA', '2023');
        $storage->createFile('/clientA', 'note.txt');
        $storage->createFile('/clientA/2023', 'return.pdf');

        $name = $this->archiver->createArchive($storage);
        $this->archiver->addDirectoryFromStorage('/clientA');
        $this->archiver->closeArchive();

        $entries = $this->archiveEntries($name);

        // The selected folder is the top of the archive; its children keep
        // their structure relative to it.
        $this->assertContains('clientA/note.txt', $entries);
        $this->assertContains('clientA/2023/return.pdf', $entries);

        // Nothing carries the absolute storage path or a leading separator.
        foreach ($entries as $entry) {
            $this->assertStringStartsNotWith('/', $entry, 'entries must be relative');
            $this->assertStringStartsNotWith('clientA/clientA', $entry, 'must not double the base');
        }
    }

    public function testMultipleFilesFromSameFolderDoNotCollide()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new MemoryAdapter();
            },
        ]);

        $storage->createDir('/', 'clientA');
        $storage->createFile('/clientA', 'a.txt');
        $storage->createFile('/clientA', 'b.txt');

        $name = $this->archiver->createArchive($storage);
        // Mirrors a multi-select batch download: both items share one parent.
        $this->archiver->addFileFromStorage('/clientA/a.txt');
        $this->archiver->addFileFromStorage('/clientA/b.txt');
        $this->archiver->closeArchive();

        $entries = $this->archiveEntries($name);
        sort($entries);
        $this->assertEquals(['a.txt', 'b.txt'], $entries);
    }

    public function testUploadingArchiveToStorage()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new MemoryAdapter();
            },
        ]);

        $storage->createDir('/', 'test');
        $storage->createDir('/test', 'sub');
        $storage->createFile('/test', 'file1.txt');
        $storage->createFile('/test', 'file2.txt');

        $name = $this->archiver->createArchive($storage);
        $this->archiver->addDirectoryFromStorage('/test');
        $this->archiver->storeArchive('/destination', 'myarchive.zip');

        $this->assertFileDoesNotExist(TEST_TMP_PATH.$name);
    }

    public function testUncompressingArchiveFromStorage()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new MemoryAdapter();
            },
        ]);

        $stream = fopen(TEST_ARCHIVE, 'r');
        $storage->store('/', 'testarchive.zip', $stream);
        fclose($stream);

        $storage->createDir('/', 'result');

        $this->archiver->uncompress('/testarchive.zip', '/result', $storage);

        $this->assertStringContainsString('testarchive', json_encode($storage->getDirectoryCollection('/')));
        $this->assertStringContainsString('onetwo', json_encode($storage->getDirectoryCollection('/result')));
    }
}
