<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Controllers\Concerns;

use Filegator\Kernel\Request;
use Filegator\Services\Audit\AuditLog;
use Filegator\Utils\Homedirs;

/**
 * One-line audit recording for the file-mutation controllers, factoring out
 * the identity + path capture that would otherwise be copied across ~10 call
 * sites (and drift). Mirrors the shared-concern pattern of
 * [ResolvesActiveHomedir].
 *
 * Applied to a controller that has $this->auth (AuthInterface) and
 * $this->storage (Filesystem). It relies on ensureActiveHomedir() having
 * already run — that sets the storage path prefix to the user's active
 * homedir, so getPathPrefix() gives us the prefix needed to turn a
 * homedir-relative path into a root-relative one. Without that prefix two
 * different clients' "/return.pdf" would be indistinguishable in the global
 * audit view.
 *
 * Never throws: identity/path resolution and the record() call are wrapped so
 * an audit failure can't turn a successful file operation into a 500.
 */
trait RecordsAuditEvents
{
    /**
     * Record one mutation. $path is the homedir-relative path the controller
     * worked with (as received from the request); it is rewritten to a
     * root-relative path for the global trail. $detail carries extra context
     * (e.g. "from <old>" for move/rename, the octal mode for chmod).
     */
    protected function recordAudit(Request $request, AuditLog $audit, string $action, string $path, $detail = null): void
    {
        try {
            // auth->user() is null for guests; getGuest() yields the
            // anonymous identity so guest-write deploys still record a row.
            $effective = $this->auth->user() ?: $this->auth->getGuest();

            $audit->record([
                'user' => $effective ? $effective->getUsername() : 'guest',
                'role' => $effective ? $effective->getRole() : 'guest',
                'action' => $action,
                'path' => $this->auditGlobalPath($path),
                'detail' => $detail !== null ? (string) $detail : null,
                'ip' => $request->getClientIp(),
            ]);
        } catch (\Throwable $ignored) {
            // Auditing is best-effort; swallow so the file op result stands.
        }
    }

    /**
     * Turn a homedir-relative path into a root-relative one by prefixing the
     * active homedir (the current storage path prefix) and normalising away
     * duplicate/leading/trailing separators.
     */
    protected function auditGlobalPath(string $userPath): string
    {
        $sep = $this->storage->getSeparator();
        $joined = $this->storage->getPathPrefix().$sep.$userPath;

        return $sep.Homedirs::normalizePath($joined, $sep);
    }
}
