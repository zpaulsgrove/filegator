<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Controllers\Concerns\RecordsAuditEvents;
use Filegator\Controllers\Concerns\ResolvesActiveHomedir;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Archiver\ArchiverInterface;
use Filegator\Services\Audit\AuditLog;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Session\SessionStorageInterface as Session;
use Filegator\Services\Storage\Filesystem;

class FileController
{
    use ResolvesActiveHomedir;
    use RecordsAuditEvents;

    const SESSION_CWD = 'current_path';

    /**
     * Session key holding the user's currently-selected folder (one of
     * their homedirs). Auto-seeded at login for single-folder users;
     * set via selectFolder() for multi-folder users.
     */
    const SESSION_ACTIVE_HOMEDIR = 'active_homedir';

    protected $session;

    protected $auth;

    protected $config;

    protected $storage;

    protected $separator;

    public function __construct(Config $config, Session $session, AuthInterface $auth, Filesystem $storage)
    {
        $this->session = $session;
        $this->config = $config;
        $this->auth = $auth;
        $this->storage = $storage;
        $this->separator = $this->storage->getSeparator();

        // NB: deliberately NO setPathPrefix() here. The path prefix is
        // resolved lazily by ensureActiveHomedir() at the top of every
        // public method, so a multi-folder user with no active folder
        // can be rejected with a clean 422 rather than a constructor
        // exception bubbling up as a 500.
    }

    public function changeDirectory(Request $request, Response $response)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $path = $request->input('to', $this->separator);

        $this->session->set(self::SESSION_CWD, $path);

        return $response->json($this->storage->getDirectoryCollection($path));
    }

    public function getDirectory(Request $request, Response $response)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $path = $request->input('dir', $this->session->get(self::SESSION_CWD, $this->separator));

        $content = $this->storage->getDirectoryCollection($path);

        return $response->json($content);
    }

    public function createNew(Request $request, Response $response, AuditLog $audit)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $type = $request->input('type', 'file');
        $name = $request->input('name');
        $path = $this->session->get(self::SESSION_CWD, $this->separator);

        if ($type == 'dir') {
            $dest = $this->storage->createDir($path, $name);
            if ($dest !== false) {
                $this->recordAuditAbsolute($request, $audit, AuditLog::ACTION_CREATE, $dest, 'folder');
            }
        }
        if ($type == 'file') {
            $dest = $this->storage->createFile($path, $name);
            if ($dest !== false) {
                $this->recordAuditAbsolute($request, $audit, AuditLog::ACTION_CREATE, $dest);
            }
        }

        return $response->json('Done');
    }

    public function copyItems(Request $request, Response $response, AuditLog $audit)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $items = $request->input('items', []);
        $destination = $request->input('destination', $this->separator);

        $events = [];
        foreach ($items as $item) {
            $dest = $item->type == 'dir'
                ? $this->storage->copyDir($item->path, $destination)
                : $this->storage->copyFile($item->path, $destination);

            if ($dest !== false) {
                $events[] = [
                    'action' => AuditLog::ACTION_COPY,
                    'path' => $this->auditNormalize($dest),
                    'detail' => 'from '.$this->auditGlobalPath($item->path),
                ];
            }
        }
        $this->recordAuditBatch($request, $audit, $events);

        return $response->json('Done');
    }

    public function moveItems(Request $request, Response $response, AuditLog $audit)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $items = $request->input('items', []);
        $destination = $request->input('destination', $this->separator);

        $events = [];
        foreach ($items as $item) {
            $full_destination = trim($destination, $this->separator)
                    .$this->separator
                    .ltrim($item->name, $this->separator);
            $dest = $this->storage->move($item->path, $full_destination);
            if ($dest !== false) {
                $events[] = [
                    'action' => AuditLog::ACTION_MOVE,
                    'path' => $this->auditNormalize($dest),
                    'detail' => 'from '.$this->auditGlobalPath($item->path),
                ];
            }
        }
        $this->recordAuditBatch($request, $audit, $events);

        return $response->json('Done');
    }

    public function zipItems(Request $request, Response $response, ArchiverInterface $archiver, AuditLog $audit)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $items = $request->input('items', []);
        $destination = $request->input('destination', $this->separator);
        $name = $request->input('name', $this->config->get('frontend_config.default_archive_name'));

        $archiver->createArchive($this->storage);

        foreach ($items as $item) {
            if ($item->type == 'dir') {
                $archiver->addDirectoryFromStorage($item->path);
            }
            if ($item->type == 'file') {
                $archiver->addFileFromStorage($item->path);
            }
        }

        $archiver->storeArchive($destination, $name);

        // The archiver exposes no success/failure signal; reaching here without
        // an exception is the success contract we record on.
        $this->recordAudit($request, $audit, AuditLog::ACTION_ZIP, rtrim($destination, $this->separator).$this->separator.$name);

        return $response->json('Done');
    }

    public function unzipItem(Request $request, Response $response, ArchiverInterface $archiver, AuditLog $audit)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $source = $request->input('item');
        $destination = $request->input('destination', $this->separator);

        $archiver->uncompress($source, $destination, $this->storage);

        $this->recordAudit($request, $audit, AuditLog::ACTION_UNZIP, $destination, 'from '.$this->auditGlobalPath($source));

        return $response->json('Done');
    }

    public function chmodItems(Request $request, Response $response, AuditLog $audit)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $items = $request->input('items', []);
        $permissions = $request->input('permissions', 0);
        /** @var null|'all'|'folders'|'files' */
        $recursive = $request->input('recursive', null);

        $events = [];
        foreach ($items as $item) {
            if ($this->storage->chmod($item->path, $permissions, $recursive)) {
                $events[] = [
                    'action' => AuditLog::ACTION_CHMOD,
                    'path' => $this->auditGlobalPath($item->path),
                    'detail' => 'mode '.$permissions,
                ];
            }
        }
        $this->recordAuditBatch($request, $audit, $events);

        return $response->json('Done');
    }

    public function renameItem(Request $request, Response $response, AuditLog $audit)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $destination = $request->input('destination', $this->separator);
        $from = $request->input('from');
        $to = $request->input('to');

        $dest = $this->storage->rename($destination, $from, $to);
        if ($dest !== false) {
            $fromPath = rtrim($destination, $this->separator).$this->separator.$from;
            $this->recordAuditAbsolute($request, $audit, AuditLog::ACTION_RENAME, $dest, 'from '.$this->auditGlobalPath($fromPath));
        }

        return $response->json('Done');
    }

    public function deleteItems(Request $request, Response $response, AuditLog $audit)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $items = $request->input('items', []);

        $events = [];
        foreach ($items as $item) {
            if ($item->type == 'dir') {
                if ($this->storage->deleteDir($item->path)) {
                    $events[] = ['action' => AuditLog::ACTION_DELETE, 'path' => $this->auditGlobalPath($item->path), 'detail' => 'folder'];
                }
            }
            if ($item->type == 'file') {
                if ($this->storage->deleteFile($item->path)) {
                    $events[] = ['action' => AuditLog::ACTION_DELETE, 'path' => $this->auditGlobalPath($item->path), 'detail' => null];
                }
            }
        }
        $this->recordAuditBatch($request, $audit, $events);

        return $response->json('Done');
    }

    public function saveContent(Request $request, Response $response, AuditLog $audit)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $path = $request->input('dir', $this->session->get(self::SESSION_CWD, $this->separator));

        $name = $request->input('name');
        $content = $request->input('content');

        $stream = tmpfile();
        fwrite($stream, $content);
        rewind($stream);

        // Overwrite in place (store() handles the delete) rather than an
        // unconditional delete-then-store: a failed write can no longer leave
        // the file destroyed with nothing recorded, and the audited path is
        // the actual stored path.
        $stored = $this->storage->store($path, $name, $stream, true);

        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($stored === false) {
            // store() returns false when the overwrite failed (e.g. the
            // delete/rename step could not complete). Surface it instead of
            // reporting a phantom success while the file was never written.
            return $response->json('Error saving file', 500);
        }

        $this->recordAuditAbsolute($request, $audit, AuditLog::ACTION_SAVE, $stored);

        return $response->json('Done');
    }

    /**
     * Switch the user's active folder. Required for multi-folder users
     * before any file-op endpoint will accept their requests; single-
     * folder users auto-seed via ensureActiveHomedir on the first
     * file-op request, but they can still call this endpoint as a
     * no-op identity check.
     *
     * Validates the requested path against the LIVE homedirs list
     * (read from the auth adapter, not from session), so an admin
     * removing a folder mid-session is honoured immediately.
     */
    public function selectFolder(Request $request, Response $response)
    {
        $current = $this->auth->user();
        if (! $current) {
            return $response->json('Not authenticated', 401);
        }

        $path = (string) $request->input('homedir', '');
        if ($path === '') {
            return $response->json(['homedir' => 'This field is required'], 422);
        }

        $fresh = $this->auth->find($current->getUsername());
        $homedirs = $fresh ? $fresh->getHomeDirs() : [];

        if (! in_array($path, $homedirs, true)) {
            return $response->json('Invalid folder', 422);
        }

        $this->session->set(self::SESSION_ACTIVE_HOMEDIR, $path);
        // Reset CWD to the new folder's root so a stale path from the
        // previous folder doesn't cause a phantom "not found" on the
        // next getdir.
        $this->session->set(self::SESSION_CWD, $this->separator);

        return $response->json(['active_homedir' => $path]);
    }
}
