<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Archiver\Adapters;

use Filegator\Services\Archiver\ArchiverInterface;
use Filegator\Services\Service;
use Filegator\Services\Storage\Filesystem as Storage;
use Filegator\Services\Tmpfs\TmpfsInterface;
use League\Flysystem\Config as Flyconfig;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;

class ZipArchiver implements Service, ArchiverInterface
{
    protected $archive;

    protected $storage;

    protected $tmpfs;

    protected $uniqid;

    protected $tmp_files = [];

    public function __construct(TmpfsInterface $tmpfs)
    {
        $this->tmpfs = $tmpfs;
    }

    public function init(array $config = [])
    {
    }

    public function createArchive(Storage $storage): string
    {
        // Cryptographically-random, unguessable name. The archive lives in the
        // shared tmpfs and is referenced by this id at download time, so a
        // predictable uniqid() would be brute-forceable; defence-in-depth on top
        // of the per-session ownership check in DownloadController.
        $this->uniqid = bin2hex(random_bytes(16));

        $this->archive = new Flysystem(
            new ZipAdapter($this->tmpfs->getFileLocation($this->uniqid))
        );

        $this->storage = $storage;

        return $this->uniqid;
    }

    public function addDirectoryFromStorage(string $path)
    {
        // Entries are stored relative to the selected directory's parent, so a
        // zip of "/clientA" contains "clientA/..." rather than the full storage
        // path. $base is the same for every child, keeping the tree intact
        // under one top-level folder.
        $base = $this->parentOf($path);

        $content = $this->storage->getDirectoryCollection($path, true);
        $this->archive->createDir($this->relativeEntry($path, $base));

        foreach ($content->all() as $item) {
            if ($item['type'] == 'dir') {
                $this->archive->createDir($this->relativeEntry($item['path'], $base));
            }
            if ($item['type'] == 'file') {
                $this->addFileFromStorage($item['path'], $base);
            }
        }
    }

    /**
     * $base is the path prefix to strip so the entry sits at (or near) the zip
     * root instead of recreating the full folder tree. When omitted it defaults
     * to the file's own parent, so a lone file lands at the zip root.
     *
     * Callers that batch multiple selected items rely on the UI invariant that
     * a selection only spans the current directory: every selected item shares
     * one parent, so stripping that parent cannot collide two different files
     * onto the same entry name. A future caller selecting across directories
     * must pass an explicit common-ancestor $base.
     */
    public function addFileFromStorage(string $path, ?string $base = null)
    {
        if ($base === null) {
            $base = $this->parentOf($path);
        }

        $file_uniqid = uniqid();

        $file = $this->storage->readStream($path);

        $this->tmpfs->write($file_uniqid, $file['stream']);

        $this->archive->write($this->relativeEntry($path, $base), $this->tmpfs->getFileLocation($file_uniqid));

        $this->tmp_files[] = $file_uniqid;
    }

    protected function parentOf(string $path): string
    {
        $parent = dirname($path);

        // dirname('/file') === '/', dirname('file') === '.' — normalise both to
        // the storage root so relativeEntry() strips a clean prefix.
        return ($parent === '.' || $parent === DIRECTORY_SEPARATOR) ? '/' : $parent;
    }

    protected function relativeEntry(string $path, string $base): string
    {
        $base = rtrim($base, '/');

        if ($base !== '' && strpos($path, $base.'/') === 0) {
            $path = substr($path, strlen($base) + 1);
        }

        return ltrim($path, '/');
    }

    public function uncompress(string $source, string $destination, Storage $storage)
    {
        $name = uniqid().'.zip';

        $remote_archive = $storage->readStream($source);
        $this->tmpfs->write($name, $remote_archive['stream']);

        $archive = new Flysystem(
            new ZipAdapter($this->tmpfs->getFileLocation($name))
        );

        $contents = $archive->listContents('/', true);

        foreach ($contents as $item) {
            $stream = null;
            if ($item['type'] == 'dir') {
                $storage->createDir($destination, $item['path']);
            }
            if ($item['type'] == 'file') {
                $stream = $archive->readStream($item['path']);
                $storage->store($destination.'/'.$item['dirname'], $item['basename'], $stream);
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->tmpfs->remove($name);
    }

    public function closeArchive()
    {
        $this->archive->getAdapter()->getArchive()->close();

        foreach ($this->tmp_files as $file) {
            $this->tmpfs->remove($file);
        }
    }

    public function storeArchive($destination, $name)
    {
        $this->closeArchive();

        $file = $this->tmpfs->readStream($this->uniqid);
        $this->storage->store($destination, $name, $file['stream']);
        if (is_resource($file['stream'])) {
            fclose($file['stream']);
        }

        $this->tmpfs->remove($this->uniqid);
    }
}

class ZipAdapter extends ZipArchiveAdapter
{
    public function write($path, $contents, Flyconfig $config)
    {
        $location = $this->applyPathPrefix($path);

        // using addFile instead of addFromString
        // is more memory efficient
        $this->archive->addFile($contents, $location);

        return compact('path', 'contents');
    }
}
