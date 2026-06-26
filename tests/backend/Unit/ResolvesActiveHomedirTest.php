<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Controllers\Concerns\ResolvesActiveHomedir;
use Filegator\Controllers\FileController;
use Filegator\Kernel\Response;
use Filegator\Services\Auth\User;
use Tests\FakeResponse;
use Tests\TestCase;

/**
 * Unit coverage for ResolvesActiveHomedir::ensureActiveHomedir() fallback and
 * branch paths the Feature suite does not reach: unauthenticated (401), guest
 * routing, empty-homedir account (422), and the single-folder session
 * auto-seed write.
 *
 * The trait host is built as an *anonymous* class created inside each test
 * method (not a top-level class). This is deliberate: PHPUnit autoloads a
 * named test-fixture class — and therefore compiles its `use TraitUnderTest`
 * — during test discovery, before the pcov per-test collection window opens,
 * so the trait's executed lines would never be attributed. Compiling the host
 * lazily inside the method keeps it inside the collection window.
 *
 * @internal
 *
 * @covers \Filegator\Controllers\Concerns\ResolvesActiveHomedir
 */
class ResolvesActiveHomedirTest extends TestCase
{
    /**
     * Build a host that applies the trait under test, wired to lightweight
     * anonymous doubles for auth / session / storage. Mirrors the controller
     * contract: the trait reaches for $this->auth, $this->session and
     * $this->storage.
     */
    private function makeHost(?User $user, ?User $guest)
    {
        $auth = new class($user, $guest) {
            private $user;

            private $guest;

            public function __construct($user, $guest)
            {
                $this->user = $user;
                $this->guest = $guest;
            }

            public function user(): ?User
            {
                return $this->user;
            }

            public function getGuest(): ?User
            {
                return $this->guest;
            }
        };

        $session = new class {
            public $store = [];

            public function get(string $key, $default = null)
            {
                return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
            }

            public function set(string $key, $data)
            {
                $this->store[$key] = $data;
            }
        };

        $storage = new class {
            public $prefix = null;

            public function setPathPrefix($prefix)
            {
                $this->prefix = $prefix;
            }
        };

        return new class($auth, $session, $storage) {
            use ResolvesActiveHomedir;

            public $auth;

            public $session;

            public $storage;

            public function __construct($auth, $session, $storage)
            {
                $this->auth = $auth;
                $this->session = $session;
                $this->storage = $storage;
            }

            public function run(Response $response): bool
            {
                return $this->ensureActiveHomedir($response);
            }
        };
    }

    private function makeUser(string $role, array $homedirs): User
    {
        $user = new User();
        $user->setRole($role);
        $user->setUsername('u@example.com');
        $user->setName('U');
        $user->setPermissions([]);
        $user->setHomedirs($homedirs);

        return $user;
    }

    public function testUnauthenticatedRequestReturns401()
    {
        // No live user and no guest -> $effective is null (lines 57-58).
        $host = $this->makeHost(null, null);
        $response = new FakeResponse();

        $this->assertFalse($host->run($response));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Not authenticated', (string) $response->getContent());
    }

    public function testGuestIsRoutedThroughBuiltinHomedir()
    {
        // Guest path applies its homedir prefix directly (lines 65-66).
        $guest = $this->makeUser('guest', ['/guesthome']);
        $host = $this->makeHost(null, $guest);
        $response = new FakeResponse();

        $this->assertTrue($host->run($response));
        $this->assertSame('/guesthome', $host->storage->prefix);
    }

    public function testAccountWithNoFoldersReturns422()
    {
        // Authenticated non-guest user whose homedir list is empty (lines 77-78).
        $user = $this->makeUser('user', []);
        $host = $this->makeHost($user, null);
        $response = new FakeResponse();

        $this->assertFalse($host->run($response));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('no folders configured', (string) $response->getContent());
    }

    public function testSingleFolderUserAutoSeedsActiveHomedirIntoSession()
    {
        // Single-folder user with no session active value: the trait must
        // write the only homedir into the session (line 89) and then apply
        // it as the storage prefix.
        $user = $this->makeUser('user', ['/onlyfolder']);
        $host = $this->makeHost($user, null);
        $response = new FakeResponse();

        // Pre-condition: nothing seeded yet, so $active (null) !== $only.
        $this->assertNull($host->session->get(FileController::SESSION_ACTIVE_HOMEDIR));

        $this->assertTrue($host->run($response));
        $this->assertSame('/onlyfolder', $host->session->get(FileController::SESSION_ACTIVE_HOMEDIR));
        $this->assertSame('/onlyfolder', $host->storage->prefix);
    }
}
