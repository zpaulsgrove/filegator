<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Fakes;

use Filegator\Services\Auth\User;
use Tests\MockUsers;

/**
 * MockUsers variant whose guest account holds the 'upload' permission, so the
 * router registers the /upload route for an anonymous (not-signed-in) request.
 *
 * Used to regression-test the guest-upload NPE fix
 * (UploadController::uploadUsername): for a real guest $this->auth->user() is
 * null, and the pre-fix code fataled on ->getUsername(). The default MockUsers
 * guest has no permissions, so the router would 404 the upload route before the
 * controller ran — this fake makes the controller path reachable.
 */
class GuestUploadAuth extends MockUsers
{
    public function getGuest(): User
    {
        $guest = new User();
        $guest->setRole('guest');
        $guest->setHomedir('/');
        $guest->setUsername('guest');
        $guest->setName('Guest');
        $guest->setPermissions(['read', 'upload']);

        return $guest;
    }
}
