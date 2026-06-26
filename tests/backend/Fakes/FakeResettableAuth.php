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
use Filegator\Services\Auth\PasswordResettableInterface;
use Filegator\Services\Auth\User;
use Filegator\Services\Auth\UsersCollection;
use Filegator\Services\Service;

/**
 * Minimal auth adapter that supports password reset, for unit-testing
 * PasswordResetService without the full JsonFile/Database stack. Only the
 * password-reset surface is meaningful; the rest of AuthInterface throws so a
 * test accidentally relying on it fails loudly rather than silently.
 */
class FakeResettableAuth implements Service, AuthInterface, PasswordResettableInterface
{
    /** @var array<string,string> lower-cased email => username */
    private array $usersByEmail = [];

    /** @var array<string,string> username => the password it was reset to */
    public array $passwordChanges = [];

    public function registerUser(string $email, string $username): void
    {
        $this->usersByEmail[strtolower($email)] = $username;
    }

    public function init(array $config = []): void {}

    public function findByEmail(string $email): ?User
    {
        $key = strtolower($email);
        if (! isset($this->usersByEmail[$key])) {
            return null;
        }
        $user = new User();
        $user->setUsername($this->usersByEmail[$key]);

        return $user;
    }

    public function setPasswordDirect(string $username, string $newPassword): void
    {
        $this->passwordChanges[$username] = $newPassword;
    }

    public function user(): ?User
    {
        return null;
    }

    public function authenticate($username, $password): bool
    {
        return false;
    }

    public function forget()
    {
        throw new \LogicException('not used in PasswordResetService tests');
    }

    public function find($username): ?User
    {
        throw new \LogicException('not used in PasswordResetService tests');
    }

    public function store(User $user)
    {
        throw new \LogicException('not used in PasswordResetService tests');
    }

    public function update($username, User $user, $password = ''): User
    {
        throw new \LogicException('not used in PasswordResetService tests');
    }

    public function add(User $user, $password): User
    {
        throw new \LogicException('not used in PasswordResetService tests');
    }

    public function delete(User $user)
    {
        throw new \LogicException('not used in PasswordResetService tests');
    }

    public function getGuest(): User
    {
        return new User();
    }

    public function allUsers(): UsersCollection
    {
        return new UsersCollection();
    }
}
