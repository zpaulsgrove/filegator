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
use Filegator\Services\Audit\AuditLog;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Session\SessionStorageInterface as Session;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Tmpfs\TmpfsInterface;

class UploadController
{
    use ResolvesActiveHomedir;
    use RecordsAuditEvents;

    protected $auth;

    protected $config;

    protected $session;

    protected $storage;

    protected $tmpfs;

    public function __construct(Config $config, Session $session, AuthInterface $auth, Filesystem $storage, TmpfsInterface $tmpfs)
    {
        $this->config = $config;
        $this->session = $session;
        $this->auth = $auth;
        $this->tmpfs = $tmpfs;
        $this->storage = $storage;

        // No setPathPrefix() here — applied lazily by ensureActiveHomedir()
        // at the top of each public method. See FileController for the
        // rationale.
    }

    /**
     * Username component for chunk-file namespacing. Authenticated users get
     * their (sanitized) real username. Guests (auth->user() === null) get a
     * per-SESSION random token, NOT a constant 'guest': the resumable
     * identifier is deterministic (size + filename), so a shared 'guest'
     * namespace would let two concurrent anonymous uploaders collide on the
     * same 'multipart_guest_<identifier>_' keys — the cross-tenant chunk
     * corruption isolated in #71. The token lives in the session so it stays
     * stable across a single guest's chunkCheck/upload requests (cookie-carried).
     */
    private function uploadUsername(): string
    {
        $user = $this->auth->user();
        if ($user) {
            return (string) preg_replace('/[^0-9a-zA-Z_]/', '', (string) $user->getUsername());
        }

        $token = $this->session->get('guest_upload_token');
        if (! $token) {
            $token = bin2hex(random_bytes(8));
            $this->session->set('guest_upload_token', $token);
        }

        return 'guest_'.preg_replace('/[^0-9a-zA-Z_]/', '', (string) $token);
    }

    public function chunkCheck(Request $request, Response $response)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $file_name = $request->input('resumableFilename', 'file');
        $identifier = (string) preg_replace('/[^0-9a-zA-Z_]/', '', (string) $request->input('resumableIdentifier'));
        $username = $this->uploadUsername();
        $chunk_number = (int) $request->input('resumableChunkNumber');

        $chunk_file = 'multipart_'.$username.'_'.$identifier.'_'.$file_name.'.part'.$chunk_number;

        if ($this->tmpfs->exists($chunk_file)) {
            return $response->json('Chunk exists', 200);
        }

        return $response->json('Chunk does not exists', 204);
    }

    public function upload(Request $request, Response $response, AuditLog $audit)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $file_name = $request->input('resumableFilename', 'file');
        $destination = $request->input('resumableRelativePath');
        $chunk_number = (int) $request->input('resumableChunkNumber');
        $total_chunks = (int) $request->input('resumableTotalChunks');
        $total_size = (int) $request->input('resumableTotalSize');
        $identifier = (string) preg_replace('/[^0-9a-zA-Z_]/', '', (string) $request->input('resumableIdentifier'));
        $username = $this->uploadUsername();

        $filebag = $request->files;
        $file = $filebag->get('file');

        $overwrite_on_upload = (bool) $this->config->get('overwrite_on_upload', false);

        // php 8.1 fix
        // remove new key 'full_path' so it can preserve compatibility with symfony FileBag
        // see https://php.watch/versions/8.1/$_FILES-full-path
        if ($file && is_array($file) && array_key_exists('full_path', $file)) {
            unset($file['full_path']);
            $filebag->set('file', $file);
            $file = $filebag->get('file');
        }

        if (! $file || ! $file->isValid() || $file->getSize() > $this->config->get('frontend_config.upload_max_size')) {
            return $response->json('Bad file', 422);
        }

        $prefix = 'multipart_'.$username.'_'.$identifier.'_';

        if ($this->tmpfs->exists($prefix.'_error')) {
            return $response->json('Chunk too big', 422);
        }

        $stream = fopen($file->getPathName(), 'r');

        $this->tmpfs->write($prefix.$file_name.'.part'.$chunk_number, $stream);

        // Count ONLY this upload's chunk parts. The glob $prefix.'*' also
        // matches the shared '_error' trap, the 'assembled' scratch, and — if a
        // client reuses one identifier across filenames — ANOTHER file's parts.
        // Summing any of those could push $chunks_size past $total_size while
        // this file's parts are still missing, triggering a premature/false
        // assembly (or a false 'Missing chunk' that wipes a concurrent upload).
        $file_part_prefix = $this->tmpfs->sanitizeFilename($prefix.$file_name.'.part');
        $chunks_size = 0;
        foreach ($this->tmpfs->findAll($prefix.'*') as $chunk) {
            if (strpos($chunk['name'], $file_part_prefix) !== 0) {
                continue;
            }
            $chunks_size += $chunk['size'];
        }

        // file too big, cleanup to protect server, set error trap
        if ($chunks_size > $this->config->get('frontend_config.upload_max_size')) {
            $this->removeFileChunks($prefix, $file_name);
            $this->tmpfs->write($prefix.'_error', '');

            return $response->json('Chunk too big', 422);
        }

        // if all the chunks are present, create final file and store it
        if ($chunks_size >= $total_size) {
            // Assemble into a scratch file namespaced to THIS user + upload
            // ($prefix already encodes username + identifier). The previous
            // implementation assembled into the bare, user-supplied $file_name
            // in the shared tmpfs dir, so two concurrent uploads of the same
            // filename (e.g. two clients each uploading "report.pdf") read,
            // wrote and deleted the same buffer — cross-tenant corruption and
            // leakage. Truncate on the first part instead of always appending,
            // so a stale buffer from an earlier/crashed assembly is overwritten
            // rather than appended onto.
            $assembled = $prefix.'assembled';
            for ($i = 1; $i <= $total_chunks; ++$i) {
                // Guard against a missing part: readStream() would fopen() a
                // non-existent file and hand back a false stream, and
                // file_put_contents($path, false) writes nothing — silently
                // storing a truncated file. Abort and clean up instead.
                if (! $this->tmpfs->exists($prefix.$file_name.'.part'.$i)) {
                    // Remove only THIS file's parts — a different filename's
                    // parts under the same identifier belong to another upload.
                    $this->removeFileChunks($prefix, $file_name);

                    return $response->json('Missing chunk', 422);
                }

                $part = $this->tmpfs->readStream($prefix.$file_name.'.part'.$i);
                $this->tmpfs->write($assembled, $part['stream'], $i > 1);
            }

            $final = $this->tmpfs->readStream($assembled);
            // The final name is the sanitized original filename, never the
            // scratch key.
            $store_name = $this->tmpfs->sanitizeFilename($file_name);
            $res = $this->storage->store($destination, $store_name, $final['stream'], $overwrite_on_upload);

            // cleanup: this file's parts plus its assembly scratch only.
            $this->tmpfs->remove($assembled);
            $this->removeFileChunks($prefix, $file_name);

            if ($res !== false) {
                // $res is the actual stored path (post collision-rename).
                $detail = $overwrite_on_upload ? 'overwritten' : null;
                $this->recordAuditAbsolute($request, $audit, AuditLog::ACTION_UPLOAD, $res, $detail);
            }

            return $res !== false ? $response->json('Stored') : $response->json('Error storing file');
        }

        return $response->json('Uploaded');
    }

    /**
     * Remove the chunk parts for a single ($prefix, $file_name) upload, leaving
     * any other filename's parts under the same identifier untouched. Matches
     * the sanitized name the parts were written under.
     */
    private function removeFileChunks(string $prefix, string $file_name): void
    {
        $needle = $this->tmpfs->sanitizeFilename($prefix.$file_name.'.part');
        foreach ($this->tmpfs->findAll($prefix.'*') as $chunk) {
            if (strpos($chunk['name'], $needle) === 0) {
                $this->tmpfs->remove($chunk['name']);
            }
        }
    }
}
