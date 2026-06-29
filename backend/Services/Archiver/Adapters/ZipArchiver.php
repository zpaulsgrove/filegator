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
        // Unpredictable id: this value is handed to the client and later used
        // to look the archive up for download. uniqid() is microtime-derived
        // and guessable/enumerable, which let a user fish for another user's
        // pending archive. 16 random bytes removes that vector (ownership is
        // also enforced in DownloadController::batchDownloadStart).
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

        // Map each zip directory path to the actual directory created under
        // $destination. createDir() upcounts a name that collides with an
        // existing non-empty folder (e.g. "folder" -> "folder (1)"); without
        // this map the directory entry would be upcounted while the files —
        // computed independently from the original zip dirname — still landed
        // in the pre-existing "folder", splitting the extracted tree.
        $dir_map = [];

        $dirs = [];
        $files = [];
        foreach ($contents as $item) {
            if ($item['type'] == 'dir') {
                $dirs[] = $item;
            } elseif ($item['type'] == 'file') {
                $files[] = $item;
            }
        }

        // Create parents before children so each child is created under its
        // parent's already-resolved (possibly upcounted) directory.
        usort($dirs, function ($a, $b) {
            return substr_count($a['path'], '/') <=> substr_count($b['path'], '/');
        });

        foreach ($dirs as $item) {
            $parent = dirname($item['path']);
            $parent_rel = ($parent === '.' || $parent === '') ? '' : ($dir_map[$parent] ?? $parent);
            $target_parent = $parent_rel === '' ? $destination : $destination.'/'.$parent_rel;

            $created = $storage->createDir($target_parent, basename($item['path']));

            // createDir() returns the prefix-applied path; only its final
            // segment can differ from the requested name (collision upcount).
            if ($created !== false) {
                $final_base = basename($created);
                $dir_map[$item['path']] = ($parent_rel === '' ? '' : $parent_rel.'/').$final_base;
            }
        }

        foreach ($files as $item) {
            $stream = $archive->readStream($item['path']);

            $dirname = $item['dirname'];
            $rel_dir = ($dirname === '' || $dirname === '.') ? '' : ($dir_map[$dirname] ?? $dirname);
            $target = $rel_dir === '' ? $destination : $destination.'/'.$rel_dir;

            $storage->store($target, $item['basename'], $stream);

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
