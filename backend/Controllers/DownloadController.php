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
use Filegator\Controllers\Concerns\ResolvesActiveHomedir;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Kernel\StreamedResponse;
use Filegator\Services\Archiver\ArchiverInterface;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Session\SessionStorageInterface as Session;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Tmpfs\TmpfsInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Mime\MimeTypes;

class DownloadController
{
    use ResolvesActiveHomedir;

    // Upper bound on how many outstanding (created-but-not-downloaded) archive
    // ids a single session tracks, so repeated batch-create calls cannot grow
    // the session payload without limit.
    const MAX_OWNED_ARCHIVES = 50;

    protected $auth;

    protected $session;

    protected $config;

    protected $storage;

    public function __construct(Config $config, Session $session, AuthInterface $auth, Filesystem $storage)
    {
        $this->session = $session;
        $this->config = $config;
        $this->auth = $auth;
        $this->storage = $storage;

        // Path prefix applied lazily by ensureActiveHomedir() — see
        // FileController for the rationale.
    }

    public function download(Request $request, Response $response, StreamedResponse $streamedResponse)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        try {
            $file = $this->storage->readStream((string) base64_decode($request->input('path')));
        } catch (\Exception $e) {
            // XHR callers (the multi-file blob download) need a real error status
            // so they can detect the failure; plain browser navigations still get
            // the redirect-to-home behaviour they expect.
            if ($request->isXmlHttpRequest()) {
                return $response->json('File not found', 404);
            }
            return $response->redirect('/');
        }

        $streamedResponse->setCallback(function () use ($file) {
            // @codeCoverageIgnoreStart
            set_time_limit(0);
            if ($file['stream']) {
                while (! feof($file['stream'])) {
                    echo fread($file['stream'], 1024 * 8);
                    if (ob_get_level() > 0) {ob_flush();}
                    flush();
                }
                fclose($file['stream']);
            }
            // @codeCoverageIgnoreEnd
        });

        $extension = pathinfo($file['filename'], PATHINFO_EXTENSION);
        $mimes = (new MimeTypes())->getMimeTypes($extension);
        $contentType = !empty($mimes) ? $mimes[0] : 'application/octet-stream';

        $disposition = HeaderUtils::DISPOSITION_ATTACHMENT;

        $download_inline = (array)$this->config->get('download_inline', ['pdf']);
        if (in_array($extension, $download_inline) || in_array('*', $download_inline)) {
            $disposition = HeaderUtils::DISPOSITION_INLINE;
        }

        $contentDisposition = HeaderUtils::makeDisposition($disposition, $file['filename'], 'file');

        $streamedResponse->headers->set(
            'Content-Disposition',
            $contentDisposition
        );
        $streamedResponse->headers->set(
            'Content-Type',
            $contentType
        );
        $streamedResponse->headers->set(
            'Content-Transfer-Encoding',
            'binary'
        );
        if (isset($file['filesize'])) {
            $streamedResponse->headers->set(
                'Content-Length',
                $file['filesize']
            );
        }
        // @codeCoverageIgnoreStart
        if (APP_ENV == 'development') {
            $streamedResponse->headers->set(
                'Access-Control-Allow-Origin',
                $request->headers->get('Origin')
            );
            $streamedResponse->headers->set(
                'Access-Control-Allow-Credentials',
                'true'
            );
        }
        // @codeCoverageIgnoreEnd

        // close session so we can continue streaming, note: dev is single-threaded
        $this->session->save();

        $streamedResponse->send();
    }

    public function batchDownloadCreate(Request $request, Response $response, ArchiverInterface $archiver)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $items = $request->input('items', []);

        $uniqid = $archiver->createArchive($this->storage);

        // Bind this archive to the creating session so another authenticated
        // user cannot download it by guessing or replaying its id (IDOR).
        // Recorded before save() because save() persists and releases the
        // session for the duration of the (potentially slow) archive build.
        $owned = (array) $this->session->get('batch_archives', []);
        $owned[$uniqid] = true;
        if (count($owned) > self::MAX_OWNED_ARCHIVES) {
            $owned = array_slice($owned, -self::MAX_OWNED_ARCHIVES, null, true);
        }
        $this->session->set('batch_archives', $owned);

        // close session
        $this->session->save();

        foreach ($items as $item) {
            if ($item->type == 'dir') {
                $archiver->addDirectoryFromStorage($item->path);
            }
            if ($item->type == 'file') {
                $archiver->addFileFromStorage($item->path);
            }
        }

        $archiver->closeArchive();

        return $response->json(['uniqid' => $uniqid]);
    }

    public function batchDownloadStart(Request $request, Response $response, StreamedResponse $streamedResponse, TmpfsInterface $tmpfs)
    {
        if (! $this->ensureActiveHomedir($response)) return;

        $uniqid = (string) preg_replace('/[^0-9a-zA-Z_]/', '', (string) $request->input('uniqid'));

        // Only allow downloading an archive THIS session created (see
        // batchDownloadCreate). Without this check, any authenticated user with
        // the batchdownload permission could stream another user's archive by
        // supplying its id.
        $owned = (array) $this->session->get('batch_archives', []);
        if (! isset($owned[$uniqid]) || ! $tmpfs->exists($uniqid)) {
            return $response->json('File not found', 404);
        }

        // Single-use: drop the binding so the id cannot be replayed.
        unset($owned[$uniqid]);
        $this->session->set('batch_archives', $owned);

        $file = $tmpfs->readStream($uniqid);

        $streamedResponse->setCallback(function () use ($file, $tmpfs, $uniqid) {
            // @codeCoverageIgnoreStart
            set_time_limit(0);
            if ($file['stream']) {
                while (! feof($file['stream'])) {
                    echo fread($file['stream'], 1024 * 8);
                    if (ob_get_level() > 0) {ob_flush();}
                    flush();
                }
                fclose($file['stream']);
            }
            $tmpfs->remove($uniqid);
            // @codeCoverageIgnoreEnd
        });

        $streamedResponse->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->config->get('frontend_config.default_archive_name'),
                'archive.zip'
            )
        );
        $streamedResponse->headers->set(
            'Content-Type',
            'application/octet-stream'
        );
        $streamedResponse->headers->set(
            'Content-Transfer-Encoding',
            'binary'
        );
        if (isset($file['filesize'])) {
            $streamedResponse->headers->set(
                'Content-Length',
                $file['filesize']
            );
        }
        // close session so we can continue streaming, note: dev is single-threaded
        $this->session->save();

        $streamedResponse->send();
    }
}
