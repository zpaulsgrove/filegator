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
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Services\Audit\AuditMailer;
use Filegator\Services\Audit\WeeklyDigest;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Auth\MfaCapableInterface;
use Filegator\Services\Auth\MfaLockout;
use Filegator\Services\Auth\PasswordResettableInterface;
use Filegator\Services\Auth\RequiresStepUpAuth;
use Filegator\Services\Auth\User;
use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Mfa\MfaService;
use Filegator\Services\Storage\Filesystem;
use Filegator\Utils\Homedirs;
use Rakit\Validation\Validator;

class AdminController
{
    use RequiresStepUpAuth;

    protected $auth;

    protected $storage;

    protected $logger;

    public function __construct(AuthInterface $auth, Filesystem $storage, LoggerInterface $logger)
    {
        $this->auth = $auth;
        $this->storage = $storage;
        $this->logger = $logger;
    }

    public function listUsers(Request $request, Response $response, WeeklyDigest $digest)
    {
        $collection = $this->auth->allUsers();
        // Adapter-specific batch read of MFA metadata in a single file scan,
        // avoiding 2N getMfaState+getEmail calls.
        $meta = method_exists($this->auth, 'allUsersMeta') ? $this->auth->allUsersMeta() : [];

        $rows = [];
        foreach ($collection->all() as $user) {
            $row = $user->jsonSerialize();
            $u = $meta[$user->getUsername()] ?? null;
            if ($u !== null) {
                $row['email'] = $u['email'];
                $row['mfa_enabled'] = (bool) $u['enabled'];
                $row['backup_codes_remaining'] = (int) $u['backup_codes_remaining'];
            }
            $rows[] = $row;
        }

        // Piggy-back the weekly digest check on the admin-panel entry point.
        // Admins almost always open the user list when administering, so this
        // is the natural place to wake the scheduler without polluting every
        // file-listing or upload request with a state-file stat. Cheap when
        // not due (one flock + JSON decode).
        $digest->maybeFire($this->auth);

        return $response->json($rows);
    }

    /**
     * Folder-access audit: the inverse of listUsers. Instead of "which folders
     * can this user reach", it answers "which users can reach this folder",
     * including access INHERITED from a parent homedir (a user rooted at
     * '/clients' — or an admin at '/' — reaches every folder beneath it).
     *
     * Access is computed purely from homedirs (the sandbox model): role and
     * permissions never widen reach, they only describe what a user may do once
     * scoped to a folder. permissions are returned for the admin's context.
     *
     * Two modes, same response shape ({separator, folders: [...]}):
     *  - no `path`  -> every distinct assigned homedir, de-duped by normalised
     *                  path so '/clientA' and '/clientA/' fold into one row;
     *  - `path` set -> just that one folder (browse-tree inspect). The supplied
     *                  path is relative to the acting admin's home directory, so
     *                  it is resolved to root-relative space first.
     */
    public function folderAccessAudit(Request $request, Response $response)
    {
        $separator = $this->storage->getSeparator();

        // Snapshot the user list once. homedirs drive the whole computation;
        // the rest is carried through for display.
        $users = [];
        foreach ($this->auth->allUsers()->all() as $user) {
            $row = $user->jsonSerialize();
            $users[] = [
                'username' => $row['username'],
                'name' => $row['name'],
                'role' => $row['role'],
                'permissions' => $row['permissions'],
                'homedirs' => Homedirs::fromArrayRow($row),
            ];
        }

        $folderKeys = $this->auditFolderKeys($request, $separator, $users);

        $folders = [];
        foreach ($folderKeys as $key) {
            // Canonical, leading-separator display form; '' is the storage root.
            $displayPath = $key === '' ? $separator : $separator.$key;

            $access = [];
            foreach ($users as $u) {
                $grantedBy = Homedirs::grantingHomedir($u['homedirs'], $displayPath, $separator);
                if ($grantedBy === null) {
                    continue;
                }
                $access[] = [
                    'username' => $u['username'],
                    'name' => $u['name'],
                    'role' => $u['role'],
                    'permissions' => $u['permissions'],
                    'granted_by' => $grantedBy,
                    'inherited' => Homedirs::normalizePath($grantedBy, $separator) !== $key,
                ];
            }

            $folders[] = [
                'path' => $displayPath,
                'user_count' => count($access),
                'access' => $access,
            ];
        }

        return $response->json([
            'separator' => $separator,
            'folders' => $folders,
        ]);
    }

    /**
     * Resolve the set of normalised folder keys to audit for the request.
     */
    protected function auditFolderKeys(Request $request, string $separator, array $users): array
    {
        $pathInput = $request->input('path', null);

        if (is_string($pathInput) && trim($pathInput) !== '') {
            // Browse-tree inspect. Reuse the exact prefix-join storeUser applies
            // (admin homedir + supplied path): identity for a root admin,
            // correct scoping for a non-root one.
            $adminBase = rtrim((string) ($this->auth->user()->getHomeDirs()[0] ?? ''), $separator);
            $resolved = $adminBase.$separator.ltrim($pathInput, $separator);

            return [Homedirs::normalizePath($resolved, $separator)];
        }

        // Assigned mode: union of every homedir, keyed by normalised path so
        // duplicates (trailing separator, etc.) collapse to one row.
        $keys = [];
        foreach ($users as $u) {
            foreach ($u['homedirs'] as $h) {
                $keys[Homedirs::normalizePath($h, $separator)] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    public function storeUser(User $user, Request $request, Response $response, Validator $validator, AuditMailer $audit, MfaService $mfa, MfaLockout $lockout, Config $config)
    {
        // Pre-validation FIRST so a malformed request does not burn a TOTP /
        // backup code on the step-up gate that fires next. No state changes
        // until step-up succeeds.
        $validator->setMessage('required', 'This field is required');
        $validation = $validator->validate($request->all(), [
            'name' => 'required',
            'username' => 'required',
            'password' => 'required',
        ]);

        if ($validation->fails()) {
            $errors = $validation->errors();

            return $response->json($errors->firstOfAll(), 422);
        }

        $homedirs = $this->normaliseHomedirsInput($request);
        if (empty($homedirs)) {
            return $response->json(['homedir' => 'This field is required'], 422);
        }

        $email = $request->input('email', null);
        if (! $this->emailValid($email)) {
            return $response->json(['email' => 'Invalid email address'], 422);
        }

        if ($this->auth->find($request->input('username'))) {
            return $response->json(['username' => 'Username already taken'], 422);
        }

        if ($emailError = $this->emailInUse($email, [$request->input('username')])) {
            return $response->json($emailError, 422);
        }

        // Validate the SUPPLIED (pre-join) value: a non-admin must name at least
        // one folder segment, so the joined path always lands strictly beneath
        // the admin's own scope rather than equalling it (the firm root). This
        // is correct regardless of where the acting admin is rooted.
        if ($homedirError = $this->assertSubfoldersForNonAdmin($homedirs, $request->input('role', 'user'))) {
            return $response->json($homedirError, 422);
        }

        // Apply the admin-prefix join to EACH supplied homedir. Same shape as
        // the pre-refactor single-string join, just looped. Admin is assumed
        // single-folder (Elliff CPA invariant); we use the first homedir of
        // whoever is acting as admin.
        $adminBase = rtrim((string) ($this->auth->user()->getHomeDirs()[0] ?? ''), $this->storage->getSeparator());
        $separator = $this->storage->getSeparator();
        $homedirs = array_map(function ($h) use ($adminBase, $separator) {
            return $adminBase . $separator . ltrim((string) $h, $separator);
        }, $homedirs);

        $check = $this->stepUpForAdmin($request, $response, $mfa, $lockout, $config);
        if (! $check['ok']) return;
        $this->auditBackupCodeIfUsed($check, $audit, $this->logger, $request->getClientIp());

        try {
            $user->setName($request->input('name'));
            $user->setUsername($request->input('username'));
            $user->setHomedirs($homedirs);
            $user->setRole($request->input('role', 'user'));
            $user->setPermissions($request->input('permissions'));
            $ret = $this->auth->add($user, $request->input('password'));

            if ($email !== null && $this->auth instanceof MfaCapableInterface) {
                $this->auth->setEmail($user->getUsername(), $email === '' ? null : $email);
            }

            $audit->userCreated(
                $this->currentAdminUsername(),
                $user->jsonSerialize(),
                ($email === null || $email === '') ? null : $email
            );
        } catch (\Exception $e) {
            return $response->json($e->getMessage(), 422);
        }

        return $response->json($ret);
    }

    public function updateUser($username, Request $request, Response $response, Validator $validator, AuditMailer $audit, MfaService $mfa, MfaLockout $lockout, Config $config)
    {
        // Pre-validation FIRST so malformed / no-op requests do not burn a
        // TOTP / backup code on the step-up gate that fires next. No state
        // changes until step-up succeeds.
        $user = $this->auth->find($username);

        if (! $user) {
            return $response->json('User not found', 422);
        }

        $validator->setMessage('required', 'This field is required');
        $validation = $validator->validate($request->all(), [
            'name' => 'required',
            'username' => 'required',
        ]);

        if ($validation->fails()) {
            $errors = $validation->errors();

            return $response->json($errors->firstOfAll(), 422);
        }

        $homedirs = $this->normaliseHomedirsInput($request);
        if (empty($homedirs)) {
            return $response->json(['homedir' => 'This field is required'], 422);
        }

        if ($username != $request->input('username') && $this->auth->find($request->input('username'))) {
            return $response->json(['username' => 'Username already taken'], 422);
        }

        $email = $request->input('email', null);
        if (! $this->emailValid($email)) {
            return $response->json(['email' => 'Invalid email address'], 422);
        }

        // Compare against the (possibly renamed) target username so keeping the
        // same email on the same user is allowed, but reusing another user's
        // email is rejected.
        if ($emailError = $this->emailInUse($email, [$username, $request->input('username')])) {
            return $response->json($emailError, 422);
        }

        if ($homedirError = $this->assertSubfoldersForNonAdmin($homedirs, $request->input('role', 'user'))) {
            return $response->json($homedirError, 422);
        }

        $check = $this->stepUpForAdmin($request, $response, $mfa, $lockout, $config);
        if (! $check['ok']) return;
        $this->auditBackupCodeIfUsed($check, $audit, $this->logger, $request->getClientIp());

        $beforeSnapshot = $user->jsonSerialize();
        $beforeEmail = null;
        if ($this->auth instanceof MfaCapableInterface) {
            $state = $this->auth->getMfaState($username);
            $beforeEmail = $state['email'] ?? null;
        }
        $passwordChanged = $request->input('password', '') !== '';

        try {
            $user->setName($request->input('name'));
            $user->setUsername($request->input('username'));
            // updateUser preserves the existing asymmetry: NO admin-prefix
            // join. Supplied homedirs are stored verbatim (matches the
            // pre-refactor scalar updateUser behaviour, pinned in Phase 1).
            $user->setHomedirs($homedirs);
            $user->setRole($request->input('role', 'user'));
            $user->setPermissions($request->input('permissions'));

            $ret = $this->auth->update($username, $user, $request->input('password', ''));

            if ($email !== null && $this->auth instanceof MfaCapableInterface) {
                $this->auth->setEmail($user->getUsername(), $email === '' ? null : $email);
            }

            // If the request omitted the email field, the previous value
            // is preserved; only an explicit empty string clears it.
            $afterEmail = $email === null ? $beforeEmail : ($email === '' ? null : $email);

            $audit->userUpdated(
                $this->currentAdminUsername(),
                $username,
                $beforeSnapshot,
                $user->jsonSerialize(),
                $beforeEmail,
                $afterEmail,
                $passwordChanged
            );

            return $response->json($ret);
        } catch (\Exception $e) {
            return $response->json($e->getMessage(), 422);
        }
    }

    public function deleteUser($username, Request $request, Response $response, AuditMailer $audit, MfaService $mfa, MfaLockout $lockout, Config $config)
    {
        // Pre-validation FIRST so a missing-target or guest-target attempt
        // does not burn a TOTP / backup code on the step-up gate.
        $user = $this->auth->find($username);

        if (! $user || $user->getUsername() == 'guest') {
            return $response->json('User not found', 422);
        }

        $check = $this->stepUpForAdmin($request, $response, $mfa, $lockout, $config);
        if (! $check['ok']) return;
        $this->auditBackupCodeIfUsed($check, $audit, $this->logger, $request->getClientIp());

        $snapshot = $user->jsonSerialize();
        $email = null;
        if ($this->auth instanceof MfaCapableInterface) {
            $state = $this->auth->getMfaState($username);
            $email = $state['email'] ?? null;
        }

        $ret = $this->auth->delete($user);

        if ($ret) {
            $audit->userDeleted($this->currentAdminUsername(), $snapshot, $email);
        }

        return $response->json($ret);
    }

    public function resetMfa($username, Request $request, Response $response, AuditMailer $audit, MfaService $mfa, MfaLockout $lockout, Config $config)
    {
        if (! $this->auth instanceof MfaCapableInterface) {
            return $response->json('Not supported', 501);
        }

        // Pre-validation FIRST. The self-reset guard and target-exists check
        // must precede step-up so that an admin who fat-fingers a self-reset
        // does not burn their own TOTP / backup code on a guaranteed 422.
        $current = $this->auth->user();
        if ($current && $current->getUsername() === $username) {
            return $response->json('Cannot reset your own MFA from the admin panel', 422);
        }

        $target = $this->auth->find($username);
        if (! $target) {
            return $response->json('User not found', 422);
        }

        $check = $this->stepUpForAdmin($request, $response, $mfa, $lockout, $config);
        if (! $check['ok']) return;
        $this->auditBackupCodeIfUsed($check, $audit, $this->logger, $request->getClientIp());

        $this->auth->disableMfa($username);
        $this->logger->log(sprintf(
            'Admin %s reset MFA for user %s from IP %s',
            $current ? $current->getUsername() : 'unknown',
            $username,
            $request->getClientIp()
        ));

        $audit->mfaResetByAdmin($this->currentAdminUsername(), $username);

        return $response->json('ok');
    }

    protected function emailValid($email): bool
    {
        if ($email === null || $email === '') return true;
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Reject an email that already belongs to a different user. Returns an
     * error payload (for a 422 response) or null when the email is free.
     *
     * Enforcing uniqueness here, before the user is persisted, prevents the
     * duplicate-email state that breaks password reset (findByEmail resolves
     * to only the first match) and avoids the half-created-user side effect of
     * relying on the adapter's setEmail() throwing after auth->add().
     *
     * $ownUsernames are the usernames that may legitimately already hold this
     * email (the target user, plus its pre-rename name on update).
     */
    protected function emailInUse($email, array $ownUsernames): ?array
    {
        if ($email === null || $email === '') {
            return null;
        }
        if (! $this->auth instanceof PasswordResettableInterface) {
            return null;
        }

        $existing = $this->auth->findByEmail($email);
        if ($existing && ! in_array($existing->getUsername(), $ownUsernames, true)) {
            return ['email' => 'Email already in use'];
        }

        return null;
    }

    /**
     * Guard that a non-admin user is only ever assigned a real subfolder, never
     * the firm root. Admins keep full freedom. Returns an error payload (for a
     * 422 response) or null when all paths are acceptable.
     *
     * This is the authoritative check — the folder picker also hides the root
     * for non-admins, but UI can be bypassed, so the backend enforces it on the
     * only two homedir write paths (storeUser, updateUser).
     */
    protected function assertSubfoldersForNonAdmin(array $homedirs, string $role): ?array
    {
        if ($role === 'admin') {
            return null;
        }

        $separator = $this->storage->getSeparator();
        foreach ($homedirs as $h) {
            if (! Homedirs::isStrictSubfolder((string) $h, $separator)) {
                return ['homedir' => 'Non-admin users must be assigned a specific subfolder, not the firm root.'];
            }
        }

        return null;
    }

    protected function currentAdminUsername(): string
    {
        $current = $this->auth->user();
        return $current ? $current->getUsername() : 'unknown';
    }

    /**
     * Resolve the acting admin's identity and dispatch the step-up trait.
     * Reads `stepup_password` and `stepup_code` from the request (distinct
     * names so they don't collide with storeUser's `password` field for the
     * new user being created). Trait degrades to a no-op when the acting
     * admin has no MFA enrolled, so this is safe on every admin endpoint.
     *
     * The whole admin-panel step-up gate is also opt-out via the top-level
     * `step_up_auth` flag (default true). When disabled, deployments accept
     * the reduced posture (a stolen admin session can do user CRUD without
     * re-auth) in exchange for not burning a TOTP on every admin write.
     * Self-service step-up (MfaController / AuthController) is unaffected.
     */
    protected function stepUpForAdmin(Request $request, Response $response, MfaService $mfa, MfaLockout $lockout, Config $config): array
    {
        if (! (bool) $config->get('step_up_auth', true)) {
            return ['ok' => true, 'used_backup' => false];
        }

        $current = $this->auth->user();
        $username = $current ? $current->getUsername() : '';
        if ($username === '') {
            // Should be unreachable — admin routes are role-gated — but
            // fail closed if it ever happens.
            $response->json('Not authenticated', 422);
            return ['ok' => false, 'used_backup' => false];
        }
        return $this->stepUpVerify(
            $response, $this->auth, $mfa, $lockout, $username, $request->getClientIp(),
            (string) $request->input('stepup_password', ''),
            (string) $request->input('stepup_code', ''),
            (bool) $request->input('stepup_use_backup', false)
        );
    }


    /**
     * Read the homedirs list from the request, supporting both shapes
     * during the transition:
     * - new: `homedirs` (array of strings)
     * - legacy: `homedir` (single string) — wrapped into a 1-element array
     *
     * Returns a clean array (trimmed, non-empty entries, re-indexed) or
     * empty array if nothing usable was provided.
     */
    protected function normaliseHomedirsInput(Request $request): array
    {
        $raw = $request->input('homedirs', null);
        if (is_array($raw)) {
            return Homedirs::clean($raw);
        }

        // Legacy single scalar — accept until the rolling-deploy window
        // for the older frontend bundle closes.
        $legacy = $request->input('homedir', null);
        if (is_string($legacy)) {
            $t = trim($legacy);
            if ($t !== '') return [$t];
        }

        return [];
    }
}
