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
use Filegator\Services\Service;
use Tests\MockUsers;

/**
 * MFA-capable adapter (extends MockUsers → JsonFile, so it satisfies
 * MfaCapableInterface) whose getMfaState() always throws. Used to drive the
 * defensive try/catch branches in AuthController:
 *   - login(): the getMfaState-throws catch (logs + failLogin).
 *   - userResponsePayload(): the getMfaState-throws catch (logs + omits field).
 *
 * verifyPasswordOnly / authenticate / find behave normally (inherited), so the
 * controller reaches the getMfaState call before the throw fires.
 */
class ThrowingMfaStateAuth extends MockUsers implements Service, AuthInterface
{
    public function getMfaState(string $username): array
    {
        throw new \RuntimeException('simulated MFA-state storage failure');
    }
}
