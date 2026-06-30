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

        // $dir_map memoizes each zip directory path -> the actual directory
        // created under $destination. createDir() upcounts a name that collides
        // with an existing non-empty folder (e.g. "folder" -> "folder (1)");
        // routing both directory entries AND every file's parent through the
        // same resolver keeps the tree together. Crucially this resolves a
        // file's ancestor directories on demand, so zips that omit explicit
        // directory entries (very common) are handled identically — otherwise
        // such a file would fall back to the original name and merge into a
        // pre-existing colliding folder.
        $dir_map = [];

        // Create explicit directory entries first so empty directories survive
        // extraction. resolveExtractDir() handles parents recursively, so the
        // listContents order does not matter.
        foreach ($contents as $item) {
            if ($item['type'] == 'dir') {
                $this->resolveExtractDir($storage, $destination, $item['path'], $dir_map);
            }
        }

        foreach ($contents as $item) {
            if ($item['type'] != 'file') {
                continue;
            }

            $stream = $archive->readStream($item['path']);

            $rel_dir = $this->resolveExtractDir($storage, $destination, $item['dirname'], $dir_map);
            $target = $rel_dir === '' ? $destination : $destination.'/'.$rel_dir;

            $storage->store($target, $item['basename'], $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->tmpfs->remove($name);
    }

    /**
     * Resolve a zip directory path to the actual (collision-resolved) directory
     * created under $destination, creating each missing segment and memoizing
     * the result in $dir_map. Recurses so a parent is resolved (and upcounted)
     * before its children, and so files whose ancestor directories have no
     * explicit zip entry are still placed in the same resolved tree.
     *
     * @param array<string,string> $dir_map
     */
    private function resolveExtractDir(Storage $storage, string $destination, string $zip_dir, array &$dir_map): string
    {
        $zip_dir = trim($zip_dir, '/');
        if ($zip_dir === '' || $zip_dir === '.') {
            return '';
        }
        if (isset($dir_map[$zip_dir])) {
            return $dir_map[$zip_dir];
        }

        $parent_rel = $this->resolveExtractDir($storage, $destination, dirname($zip_dir), $dir_map);
        $target_parent = $parent_rel === '' ? $destination : $destination.'/'.$parent_rel;

        $created = $storage->createDir($target_parent, basename($zip_dir));

        // createDir() returns the prefix-applied path; only its final segment
        // can differ from the requested name (collision upcount). On failure
        // fall back to the literal name — store() still auto-creates parents.
        $base = $created !== false ? basename($created) : basename($zip_dir);
        $rel = $parent_rel === '' ? $base : $parent_rel.'/'.$base;

        $dir_map[$zip_dir] = $rel;

        return $rel;
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
