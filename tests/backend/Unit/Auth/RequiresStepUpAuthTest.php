<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit\Auth;

use Filegator\Kernel\Response;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Auth\MfaCapableInterface;
use Filegator\Services\Auth\MfaLockout;
use Filegator\Services\Auth\RequiresStepUpAuth;
use Filegator\Services\Mfa\MfaService;
use Tests\FakeResponse;
use Tests\TestCase;

/**
 * Marker interface so a single mock can stand in for an auth adapter that is
 * both an AuthInterface (the param type stepUpVerify expects) and
 * MfaCapableInterface (the instanceof gate inside it).
 */
interface MfaCapableAuthForTest extends AuthInterface, MfaCapableInterface
{
}

/**
 * Unit coverage for the step-up lockout branch of RequiresStepUpAuth. When the
 * brute-force lockout is active the verifier must refuse (ok=false, 429) before
 * consuming any credential — a surviving mutant that flips ok to true would let
 * a rate-limited caller proceed with the protected action.
 *
 * @internal
 */
class RequiresStepUpAuthTest extends TestCase
{
    use RequiresStepUpAuth {
        stepUpVerify as public;
    }

    public function testLockedOutStepUpIsRefusedWithoutConsumingCredentials()
    {
        $response = new FakeResponse();

        $auth = $this->createMock(MfaCapableAuthForTest::class);
        $auth->method('getMfaState')->willReturn(['enabled' => true]);

        $lockout = $this->createMock(MfaLockout::class);
        $lockout->method('isLocked')->willReturn(true);

        // MfaService is only reached after the lockout check, so a bare mock
        // (constructor bypassed) is enough — it is never touched on this path.
        $mfa = $this->createMock(MfaService::class);

        $result = $this->stepUpVerify(
            $response,
            $auth,
            $mfa,
            $lockout,
            'alice',
            '1.2.3.4',
            'correct-horse',
            '123456',
            false
        );

        $this->assertFalse($result['ok'], 'a locked-out step-up must report failure');
        $this->assertFalse($result['used_backup']);
        $this->assertSame(429, $response->getStatusCode(), 'lockout must surface as 429');
    }
}
