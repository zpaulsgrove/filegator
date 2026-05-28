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
use Filegator\Services\Auth\UsersCollection;
use Filegator\Services\Service;
use Filegator\Services\Session\SessionStorageInterface;
use Tests\MockUsers;

/**
 * A minimal auth adapter that deliberately does NOT implement
 * MfaCapableInterface. Used to verify that userResponsePayload omits
 * mfa_enabled (and does not throw) when the adapter has no MFA support —
 * the regression case for LDAP/WPAuth adapters.
 *
 * Wraps MockUsers via composition so the user store works normally, but the
 * class itself is not in the JsonFile hierarchy and therefore never satisfies
 * `instanceof MfaCapableInterface`.
 */
class NonMfaCapableAuth implements Service, AuthInterface
{
    private MockUsers $inner;

    public function __construct(SessionStorageInterface $session)
    {
        $this->inner = new MockUsers($session);
    }

    public function init(array $config = []): void
    {
        $this->inner->init($config);
    }

    public function user(): ?User
    {
        return $this->inner->user();
    }

    public function authenticate($username, $password): bool
    {
        return $this->inner->authenticate($username, $password);
    }

    public function forget()
    {
        return $this->inner->forget();
    }

    public function find($username): ?User
    {
        return $this->inner->find($username);
    }

    public function store(User $user)
    {
        return $this->inner->store($user);
    }

    public function update($username, User $user, $password = ''): User
    {
        return $this->inner->update($username, $user, $password);
    }

    public function add(User $user, $password): User
    {
        return $this->inner->add($user, $password);
    }

    public function delete(User $user)
    {
        return $this->inner->delete($user);
    }

    public function getGuest(): User
    {
        return $this->inner->getGuest();
    }

    public function allUsers(): UsersCollection
    {
        return $this->inner->allUsers();
    }
}
