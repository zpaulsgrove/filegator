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

    public function chunkCheck(Request $request, Response $response)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $file_name = $request->input('resumableFilename', 'file');
        $identifier = (string) preg_replace('/[^0-9a-zA-Z_]/', '', (string) $request->input('resumableIdentifier'));
        $username = (string) preg_replace('/[^0-9a-zA-Z_]/', '', (string) $this->auth->user()->getUsername());
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
        $username = (string) preg_replace('/[^0-9a-zA-Z_]/', '', (string) $this->auth->user()->getUsername());

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

        // check if all the parts present, and create the final destination file
        $chunks_size = 0;
        foreach ($this->tmpfs->findAll($prefix.'*') as $chunk) {
            $chunks_size += $chunk['size'];
        }

        // file too big, cleanup to protect server, set error trap
        if ($chunks_size > $this->config->get('frontend_config.upload_max_size')) {
            foreach ($this->tmpfs->findAll($prefix.'*') as $tmp_chunk) {
                $this->tmpfs->remove($tmp_chunk['name']);
            }
            $this->tmpfs->write($prefix.'_error', '');

            return $response->json('Chunk too big', 422);
        }

        // if all the chunks are present, create final file and store it
        if ($chunks_size >= $total_size) {
            // Assemble into a per-user, per-upload namespaced tmpfs key — NOT
            // the bare, client-controlled $file_name. The tmpfs directory is
            // shared across all users/sessions, so writing the reassembled file
            // under the bare name let two concurrent uploads that happen to
            // share a filename append into the same file and corrupt or leak
            // each other's bytes (CWE-362 / CWE-668). Serialize the assembly
            // with an atomic O_EXCL marker so only one request reassembles a
            // given upload at a time.
            $assembled = $prefix.'assembled';

            if (! $this->tmpfs->addIfAbsent($prefix.'_assembling')) {
                // Another request for this same upload is already assembling it.
                return $response->json('Uploaded');
            }

            try {
                for ($i = 1; $i <= $total_chunks; ++$i) {
                    $part = $this->tmpfs->readStream($prefix.$file_name.'.part'.$i);
                    // The first write truncates (append=false) so a stale
                    // assembled file from an interrupted run cannot accumulate;
                    // every subsequent part appends.
                    $this->tmpfs->write($assembled, $part['stream'], $i > 1);
                }

                $final = $this->tmpfs->readStream($assembled);
                // Store under the sanitized client filename (unchanged
                // behaviour), sourced from the namespaced assembled stream.
                $res = $this->storage->store($destination, $this->tmpfs->sanitizeFilename($file_name), $final['stream'], $overwrite_on_upload);
            } finally {
                // cleanup: removes the parts, the assembled file, and the
                // _assembling / _error markers (all share $prefix).
                foreach ($this->tmpfs->findAll($prefix.'*') as $expired_chunk) {
                    $this->tmpfs->remove($expired_chunk['name']);
                }
            }

            if ($res !== false) {
                // $res is the actual stored path (post collision-rename).
                $detail = $overwrite_on_upload ? 'overwritten' : null;
                $this->recordAuditAbsolute($request, $audit, AuditLog::ACTION_UPLOAD, $res, $detail);
            }

            return $res !== false ? $response->json('Stored') : $response->json('Error storing file');
        }

        return $response->json('Uploaded');
    }
}
