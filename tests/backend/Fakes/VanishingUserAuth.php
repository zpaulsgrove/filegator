<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Fakes;

use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Auth\User;
use Filegator\Services\Service;
use Tests\MockUsers;

/**
 * MFA-capable adapter whose verifyPasswordOnly() succeeds for any credentials
 * but whose find() always returns null. This simulates a TOCTOU race where the
 * user record is deleted between password verification and the subsequent
 * find() lookup. Drives AuthController::login()'s `! $user` → failLogin branch
 * (the record-vanished-after-password-check guard).
 */
class VanishingUserAuth extends MockUsers implements Service, AuthInterface
{
    public function verifyPasswordOnly(string $username, string $password): bool
    {
        return true;
    }

    public function find($username): ?User
    {
        // Keep the guest account resolvable — the router/guard bootstrap calls
        // getGuest() which resolves through find('guest'). Only the
        // authenticating user "vanishes", which is what login()'s guard checks.
        if ($username === 'guest') {
            return parent::find($username);
        }

        return null;
    }
}
