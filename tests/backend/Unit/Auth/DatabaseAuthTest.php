<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit\Auth;

use Filegator\Services\Auth\Adapters\Database;
use Filegator\Services\Auth\User;
use Filegator\Services\Session\SessionStorageInterface;

/**
 * @internal
 */
class DatabaseAuthTest extends AuthTest
{
    protected $conn;

    public function setAuth()
    {
        $this->auth = new Database($this->session);
        $this->auth->init([
            'driver' => 'sqlite',
            'dsn' => 'sqlite::memory:',
            'database' => 'tests/backend/tmp/temp/sqlite',
        ]);

        $this->conn = $this->auth->getConnection();

        $this->conn->query('DROP TABLE IF EXISTS [users]');
        $this->conn->query('CREATE TABLE [users] (
                [id] INTEGER PRIMARY KEY NOT NULL,
                [username] VARCHAR(255) NOT NULL,
                [name] VARCHAR(255) NOT NULL,
                [role] VARCHAR(20) NOT NULL,
                [permissions] VARCHAR(100) NOT NULL,
                [homedir] VARCHAR(1000) NOT NULL,
                [password] VARCHAR(255) NOT NULL

            )');
        $this->conn->fetch('SELECT * FROM users WHERE username = ?', 'admin');
    }

    /**
     * user() rebuilds the session hash from the current DB row and compares it
     * to the hash captured at authentication. If the stored row changes out
     * from under a live session, the hashes diverge and the session must be
     * treated as invalid (force re-auth) — these tests pin each component of
     * buildSessionHash() so a dropped term or a flipped comparison is caught.
     */
    public function testValidSessionStillResolvesWhenRowUnchanged()
    {
        $this->addAdmin('secret123');
        $this->assertTrue($this->auth->authenticate('admin@example.com', 'secret123'));

        // Sanity: the matching-hash path returns the user.
        $this->assertNotNull($this->auth->user());
    }

    public function testTamperedPasswordForcesReauth()
    {
        $this->addAdmin('secret123');
        $this->auth->authenticate('admin@example.com', 'secret123');
        $this->assertNotNull($this->auth->user());

        $this->conn->query('UPDATE users SET', ['password' => 'rotated-hash'], 'WHERE username = ?', 'admin@example.com');

        $this->assertNull($this->auth->user(), 'a changed password row must invalidate the live session');
    }

    public function testTamperedRoleForcesReauth()
    {
        $this->addAdmin('secret123');
        $this->auth->authenticate('admin@example.com', 'secret123');
        $this->assertNotNull($this->auth->user());

        $this->conn->query('UPDATE users SET', ['role' => 'guest'], 'WHERE username = ?', 'admin@example.com');

        $this->assertNull($this->auth->user(), 'a role change must invalidate the live session');
    }

    public function testTamperedPermissionsForcesReauth()
    {
        $this->addAdmin('secret123');
        $this->auth->authenticate('admin@example.com', 'secret123');
        $this->assertNotNull($this->auth->user());

        $this->conn->query('UPDATE users SET', ['permissions' => 'zzzz'], 'WHERE username = ?', 'admin@example.com');

        $this->assertNull($this->auth->user(), 'a permissions change must invalidate the live session');
    }

    /**
     * A successful login must regenerate the session id (and destroy the old
     * one) so a pre-auth fixed session cannot be reused — the session-fixation
     * defense. Driven through a spy session because SessionStorage exposes no
     * id; pins both that migrate() is called and that destroy === true.
     */
    public function testAuthenticateRegeneratesSessionToPreventFixation()
    {
        $spy = new class() implements SessionStorageInterface {
            public array $store = [];

            public int $migrateCalls = 0;

            public $lastDestroy = null;

            public function set(string $key, $data)
            {
                $this->store[$key] = $data;
            }

            public function get(string $key, $default = null)
            {
                return $this->store[$key] ?? $default;
            }

            public function invalidate()
            {
                $this->store = [];
            }

            public function save() {}

            public function migrate($destroy = false, $lifetime = null): bool
            {
                $this->migrateCalls++;
                $this->lastDestroy = $destroy;

                return true;
            }
        };

        $auth = new Database($spy);
        $auth->init([
            'driver' => 'sqlite',
            'dsn' => 'sqlite::memory:',
            'database' => 'tests/backend/tmp/temp/sqlite_fixation',
        ]);
        $conn = $auth->getConnection();
        $conn->query('DROP TABLE IF EXISTS [users]');
        $conn->query('CREATE TABLE [users] (
                [id] INTEGER PRIMARY KEY NOT NULL,
                [username] VARCHAR(255) NOT NULL,
                [name] VARCHAR(255) NOT NULL,
                [role] VARCHAR(20) NOT NULL,
                [permissions] VARCHAR(100) NOT NULL,
                [homedir] VARCHAR(1000) NOT NULL,
                [password] VARCHAR(255) NOT NULL
            )');

        $user = new User();
        $user->setRole('admin');
        $user->setHomedir('/');
        $user->setUsername('fix@example.com');
        $user->setName('Fix');
        $auth->add($user, 'secret123');

        $this->assertTrue($auth->authenticate('fix@example.com', 'secret123'));

        $this->assertGreaterThanOrEqual(1, $spy->migrateCalls, 'login must regenerate the session id');
        $this->assertTrue($spy->lastDestroy, 'the previous session must be destroyed on regeneration');
    }
}
