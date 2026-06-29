<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Storage;

use Filegator\Services\Service;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Util;

class Filesystem implements Service
{
    protected $separator;

    protected $storage;

    protected $path_prefix;

    public function init(array $config = [])
    {
        $this->separator = $config['separator'];
        $this->path_prefix = $this->separator;

        $adapter = $config['adapter'];
        $config = isset($config['config']) ? $config['config'] : [];

        $this->storage = new Flysystem($adapter(), $config);
    }

    /**
     * @return string|false the final (possibly upcounted) destination path on
     *                      success, false on failure. Returning the actual
     *                      path lets callers (e.g. the audit log) record where
     *                      the entry really landed after collision renaming.
     */
    public function createDir(string $path, string $name)
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while (! empty($this->storage->listContents($destination, true))) {
            $destination = $this->upcountName($destination);
        }

        return $this->storage->createDir($destination) ? $destination : false;
    }

    /**
     * @return string|false the final (possibly upcounted) destination path on
     *                      success, false on failure.
     */
    public function createFile(string $path, string $name)
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        while ($this->storage->has($destination)) {
            $destination = $this->upcountName($destination);
        }

        return $this->storage->put($destination, '') ? $destination : false;
    }

    public function fileExists(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->storage->has($path);
    }

    public function isDir(string $path)
    {
        $path = $this->applyPathPrefix($path);

        return $this->storage->getSize($path) === false;
    }

    /**
     * @return string|false the final (possibly upcounted) destination path on
     *                      success, false on failure.
     */
    public function copyFile(string $source, string $destination)
    {
        $source = $this->applyPathPrefix($source);
        $destination = $this->joinPaths($this->applyPathPrefix($destination), $this->getBaseName($source));

        while ($this->storage->has($destination)) {
            $destination = $this->upcountName($destination);
        }

        return $this->storage->copy($source, $destination) ? $destination : false;
    }

    /**
     * @return string|false the final destination directory path on success,
     *                      false if any inner copy/createDir failed (so a
     *                      partial copy is not mis-reported as a success).
     */
    public function copyDir(string $source, string $destination)
    {
        $source = $this->applyPathPrefix($this->addSeparators($source));
        $destination = $this->applyPathPrefix($this->addSeparators($destination));
        $source_dir = $this->getBaseName($source);
        $real_destination = $this->joinPaths($destination, $source_dir);

        while (! empty($this->storage->listContents($real_destination, true))) {
            $real_destination = $this->upcountName($real_destination);
        }

        $contents = $this->storage->listContents($source, true);

        if (empty($contents)) {
            return $this->storage->createDir($real_destination) ? $real_destination : false;
        }

        $ok = true;
        foreach ($contents as $file) {
            $source_path = $this->separator.ltrim($file['path'], $this->separator);
            $path = substr($source_path, strlen($source), strlen($source_path));

            if ($file['type'] == 'dir') {
                $ok = ($this->storage->createDir($this->joinPaths($real_destination, $path)) !== false) && $ok;

                continue;
            }

            if ($file['type'] == 'file') {
                $ok = $this->storage->copy($file['path'], $this->joinPaths($real_destination, $path)) && $ok;
            }
        }

        return $ok ? $real_destination : false;
    }

    public function deleteDir(string $path)
    {
        return $this->storage->deleteDir($this->applyPathPrefix($path));
    }

    public function deleteFile(string $path)
    {
        return $this->storage->delete($this->applyPathPrefix($path));
    }

    public function readStream(string $path): array
    {
        if ($this->isDir($path)) {
            throw new \Exception('Cannot stream directory');
        }

        $path = $this->applyPathPrefix($path);

        return [
            'filename' => $this->getBaseName($path),
            'stream' => $this->storage->readStream($path),
            'filesize' => $this->storage->getSize($path),
        ];
    }

    /**
     * @return string|false the final (possibly upcounted) destination path on
     *                      success, false on failure.
     */
    public function move(string $from, string $to)
    {
        $from = $this->applyPathPrefix($from);
        $to = $this->applyPathPrefix($to);

        while ($this->storage->has($to)) {
            $to = $this->upcountName($to);
        }

        return $this->storage->rename($from, $to) ? $to : false;
    }

    /**
     * @return string|false the final (possibly upcounted) destination path on
     *                      success, false on failure.
     */
    public function rename(string $destination, string $from, string $to)
    {
        $from = $this->joinPaths($this->applyPathPrefix($destination), $from);
        $to = $this->joinPaths($this->applyPathPrefix($destination), $to);

        while ($this->storage->has($to)) {
            $to = $this->upcountName($to);
        }

        return $this->storage->rename($from, $to) ? $to : false;
    }

    /**
     * @return string|false the final (possibly upcounted) destination path on
     *                      success, false on failure.
     */
    public function store(string $path, string $name, $resource, bool $overwrite = false)
    {
        $destination = $this->joinPaths($this->applyPathPrefix($path), $name);

        if (! $overwrite) {
            while ($this->storage->has($destination)) {
                $destination = $this->upcountName($destination);
            }

            return $this->storage->putStream($destination, $resource) ? $destination : false;
        }

        // Overwrite-in-place. If there is nothing to clobber, write directly.
        if (! $this->storage->has($destination)) {
            return $this->storage->putStream($destination, $resource) ? $destination : false;
        }

        // Write the new content to a sibling temp file FIRST, so a write that
        // fails partway (disk full, dropped SFTP connection, permission flip)
        // cannot destroy the existing file. Only once the new bytes are safely
        // on disk do we delete the original and move the temp into place. This
        // shrinks the data-loss window from the whole (potentially large) write
        // down to the delete+rename step. Flysystem's rename() refuses an
        // existing target, so the delete must precede it. See GitHub issue #57.
        $temp = $destination.'.filegator-tmp.'.uniqid('', true);
        while ($this->storage->has($temp)) {
            $temp = $destination.'.filegator-tmp.'.uniqid('', true);
        }

        if (! $this->storage->putStream($temp, $resource)) {
            // Write failed: drop any partial temp; the original is untouched.
            if ($this->storage->has($temp)) {
                $this->storage->delete($temp);
            }

            return false;
        }

        // Flysystem's rename() refuses an existing target, so the original must
        // be removed first. If the delete fails, abort while the original and
        // the new bytes are both still intact rather than attempting a rename
        // that is guaranteed to fail.
        if (! $this->storage->delete($destination)) {
            if ($this->storage->has($temp)) {
                $this->storage->delete($temp);
            }

            return false;
        }

        // A failed rename would otherwise leave the destination missing and the
        // new bytes stranded in the temp file while store() falsely reported
        // success. Clean up the temp sibling and surface the failure so callers
        // (and the audit log) don't record a phantom save.
        if (! $this->storage->rename($temp, $destination)) {
            if ($this->storage->has($temp)) {
                $this->storage->delete($temp);
            }

            return false;
        }

        return $destination;
    }
    
    /**
     * Change file permissions one item, with optional recursion
     * 
     * @param string $path
     * @param int $permissions
     * @param null|'all'|'folders'|'files' $recursive
     * @return bool
     * @throws \Exception
     */
    public function chmod(string $path, int $permissions, string $recursive = null)
    {
        $path = $this->applyPathPrefix($path);
        $path = Util::normalizePath($path);
        $adapter = $this->storage->getAdapter();
        
        $mainResult = $this->chmodItem($path, $permissions);
        if ($recursive !== null) {
            if (method_exists($adapter, 'setRecurseManually')) {
                $adapter->setRecurseManually(true); // this is needed for ftp driver
            }
            $contents = $this->storage->listContents($path, true);
            foreach ($contents as $item) {
                try {
                    if ($item['type'] == 'dir' && ($recursive == 'all' || $recursive == 'folders')) {
                        $this->chmodItem($item['path'], $permissions);
                    }
                    if ($item['type'] == 'file' && ($recursive == 'all' || $recursive == 'files')) {
                        $this->chmodItem($item['path'], $permissions);
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }
        
        return $mainResult;
    }
    /**
     * Change file permissions for a single item
     * 
     * @param string $path
     * @param int $permissions
     * @return bool
     * @throws \Exception
     */
    public function chmodItem(string $path, int $permissions)
    {
        $adapter = $this->storage->getAdapter();
        
        switch (get_class($adapter)) {
            case 'League\Flysystem\Adapter\Local':
                $absolutePath = $adapter->applyPathPrefix($path);
                return chmod($absolutePath, octdec($permissions));
                break;
            case 'League\Flysystem\Sftp\SftpAdapter':
                return $adapter->getConnection()->chmod($path, octdec($permissions));
                break;
            case 'Filegator\Services\Storage\Adapters\FilegatorFtp':
                return ftp_chmod($adapter->getConnection(), octdec($permissions), $path) !== false;
                break;
            default:
                throw new \Exception('Selected adapter does not support unix permissions');
                break;
        }
    }

    public function setPathPrefix(string $path_prefix)
    {
        $this->path_prefix = $this->addSeparators($path_prefix);
    }

    public function getSeparator()
    {
        return $this->separator;
    }

    public function getPathPrefix(): string
    {
        return $this->path_prefix;
    }

    public function getDirectoryCollection(string $path, bool $recursive = false): DirectoryCollection
    {
        $collection = new DirectoryCollection($path);

        foreach ($this->storage->listContents($this->applyPathPrefix($path), $recursive) as $entry) {
            // By default only 'path' and 'type' is present

            $name = $this->getBaseName($entry['path']);
            $userpath = $this->stripPathPrefix($entry['path']);
            $dirname = isset($entry['dirname']) ? $entry['dirname'] : $path;
            $size = isset($entry['size']) ? $entry['size'] : 0;
            $timestamp = isset($entry['timestamp']) ? $entry['timestamp'] : 0;
            $permissions = $this->getPermissions($entry);

            $collection->addFile($entry['type'], $userpath, $name, $size, $timestamp, $permissions);
        }

        if (! $recursive && $this->addSeparators($path) !== $this->separator) {
            $collection->addFile('back', $this->getParent($path), '..', 0, 0, -1);
        }

        return $collection;
    }
    
    protected function getPermissions(array $entry): int
    {
        $adapter = $this->storage->getAdapter();
        $path = $entry['path'];
        
        switch (get_class($adapter)) {
            case 'League\Flysystem\Adapter\Local':
                $path = $adapter->applyPathPrefix($path); // get the full path
                $permissions = substr(sprintf('%o', fileperms($path)), -3);
                return $permissions;
                break;
            case 'League\Flysystem\Sftp\SftpAdapter':
                $stat = $adapter->getConnection()->stat($path);
                return $stat && isset($stat['permissions']) ? substr(decoct($stat['permissions']), -3) : -1;
                break;
            case 'Filegator\Services\Storage\Adapters\FilegatorFtp':
                return isset($entry['permissions']) ? $entry['permissions'] : -1;
                break;
        }
        return -1;
    }

    protected function upcountCallback($matches)
    {
        $index = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
        $ext = isset($matches[2]) ? $matches[2] : '';

        return ' ('.$index.')'.$ext;
    }

    protected function upcountName($name)
    {
        return preg_replace_callback(
            '/(?:(?: \(([\d]+)\))?(\.[^.]+))?$/',
            [$this, 'upcountCallback'],
            $name,
            1
        );
    }

    private function applyPathPrefix(string $path): string
    {
        if ($path == '..'
            || strpos($path, '..'.$this->separator) !== false
            || strpos($path, $this->separator.'..') !== false
        ) {
            $path = $this->separator;
        }

        return $this->joinPaths($this->getPathPrefix(), $path);
    }

    private function stripPathPrefix(string $path): string
    {
        $path = $this->separator.ltrim($path, $this->separator);

        if (substr($path, 0, strlen($this->getPathPrefix())) == $this->getPathPrefix()) {
            $path = $this->separator.substr($path, strlen($this->getPathPrefix()));
        }

        return $path;
    }

    private function addSeparators(string $dir): string
    {
        if (! $dir || $dir == $this->separator || ! trim($dir, $this->separator)) {
            return $this->separator;
        }

        return $this->separator.trim($dir, $this->separator).$this->separator;
    }

    private function joinPaths(string $path1, string $path2): string
    {
        $path1 = $this->escapeDots($path1);
        $path2 = $this->escapeDots($path2);

        if (! $path2 || ! trim($path2, $this->separator)) {
            return $this->addSeparators($path1);
        }

        return $this->addSeparators($path1).ltrim($path2, $this->separator);
    }

    private function getParent(string $dir): string
    {
        if (! $dir || $dir == $this->separator || ! trim($dir, $this->separator)) {
            return $this->separator;
        }

        $tmp = explode($this->separator, trim($dir, $this->separator));
        array_pop($tmp);

        return $this->separator.trim(implode($this->separator, $tmp), $this->separator);
    }

    private function getBaseName(string $path): string
    {
        if (! $path || $path == $this->separator || ! trim($path, $this->separator)) {
            return $this->separator;
        }

        $tmp = explode($this->separator, trim($path, $this->separator));

        return  (string) array_pop($tmp);
    }

    private function escapeDots(string $path): string
    {
        $path = preg_replace('/\\\+\.{2,}/', '', $path);
        $path = preg_replace('/\.{2,}\\\+/', '', $path);
        $path = preg_replace('/\/+\.{2,}/', '', $path);
        $path = preg_replace('/\.{2,}\/+/', '', $path);

        return $path;
    }
}
