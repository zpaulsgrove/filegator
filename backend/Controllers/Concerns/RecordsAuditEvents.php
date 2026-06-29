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
 * Audit recording for the file-mutation controllers, factoring out the
 * identity + path capture that would otherwise be copied across ~10 call
 * sites (and drift). Mirrors the shared-concern pattern of
 * [ResolvesActiveHomedir], whose $resolvedActiveHomedir this relies on to
 * turn a homedir-relative path into a root-relative one — so two clients'
 * "/return.pdf" stay distinguishable in the global trail.
 *
 * Two path flavours:
 *  - recordAudit*()        — caller passes a HOMEDIR-RELATIVE path (delete,
 *                            chmod, and "from" sources); prefixed here.
 *  - recordAuditAbsolute() — caller passes the ACTUAL post-rename path the
 *                            Filesystem returned (create/copy/move/rename/
 *                            upload/save); already root-relative, just
 *                            normalised. This is what keeps the trail honest
 *                            when a collision upcounts "x.pdf" -> "x (1).pdf".
 *
 * Never throws: identity/path resolution and the record() call are wrapped so
 * an audit failure can't turn a successful file operation into a 500.
 */
trait RecordsAuditEvents
{
    /** Record one event whose path is homedir-relative. */
    protected function recordAudit(Request $request, AuditLog $audit, string $action, string $userPath, $detail = null): void
    {
        $this->emitAudit($request, $audit, [[
            'action' => $action,
            'path' => $this->auditGlobalPath($userPath),
            'detail' => $detail,
        ]]);
    }

    /** Record one event whose path is already root-relative (as returned by Filesystem). */
    protected function recordAuditAbsolute(Request $request, AuditLog $audit, string $action, string $absolutePath, $detail = null): void
    {
        $this->emitAudit($request, $audit, [[
            'action' => $action,
            'path' => $this->auditNormalize($absolutePath),
            'detail' => $detail,
        ]]);
    }

    /**
     * Record many events in ONE locked append — used by bulk operations
     * (copy/move/delete/chmod) so an N-item request does not pay N separate
     * open/lock/flush cycles. Each item: ['action'=>, 'path'=> (already final,
     * root-relative), 'detail'=>].
     *
     * @param array<int,array<string,mixed>> $items
     */
    protected function recordAuditBatch(Request $request, AuditLog $audit, array $items): void
    {
        if (empty($items)) {
            return;
        }
        $this->emitAudit($request, $audit, $items);
    }

    /**
     * @param array<int,array<string,mixed>> $items each ['action','path','detail'?]
     */
    private function emitAudit(Request $request, AuditLog $audit, array $items): void
    {
        try {
            $u = $this->auth->user() ?: $this->auth->getGuest();
            $user = $u ? $u->getUsername() : 'guest';
            $role = $u ? $u->getRole() : 'guest';
            $ip = $request->getClientIp();

            $events = [];
            foreach ($items as $it) {
                $events[] = [
                    'user' => $user,
                    'role' => $role,
                    'action' => $it['action'],
                    'path' => $it['path'],
                    'detail' => isset($it['detail']) && $it['detail'] !== null ? (string) $it['detail'] : null,
                    'ip' => $ip,
                ];
            }
            $audit->recordMany($events);
        } catch (\Throwable $ignored) {
            // Auditing is best-effort; swallow so the file op result stands.
        }
    }

    /** Prefix a homedir-relative path with the resolved active homedir, then normalise. */
    protected function auditGlobalPath(string $userPath): string
    {
        $sep = $this->storage->getSeparator();
        $homedir = isset($this->resolvedActiveHomedir) ? (string) $this->resolvedActiveHomedir : '';

        return $this->auditNormalize($homedir.$sep.$userPath);
    }

    /** Canonical leading-separator display form, collapsing duplicate separators. */
    protected function auditNormalize(string $path): string
    {
        $sep = $this->storage->getSeparator();

        return $sep.Homedirs::normalizePath($path, $sep);
    }
}
