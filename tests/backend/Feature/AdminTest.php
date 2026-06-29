<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @internal
 */
class AdminTest extends TestCase
{
    public function testOnlyAdminCanPerformUserActions()
    {
        $this->signOut();

        $this->sendRequest('GET', '/listusers');
        $this->assertStatus(404);

        $this->sendRequest('POST', '/storeuser');
        $this->assertStatus(404);

        $this->sendRequest('POST', '/updateuser/test@example.com');
        $this->assertStatus(404);

        $this->sendRequest('POST', '/deleteuser/test@example.com');
        $this->assertStatus(404);

        // reset_mfa is an admin-only destructive action too; guests must not
        // reach it.
        $this->sendRequest('POST', '/admin/users/jane@example.com/reset_mfa');
        $this->assertStatus(404);
    }

    public function testSignedInNonAdminCannotPerformUserActions()
    {
        // A regular authenticated user (role "user") must be gated out of every
        // admin endpoint by ROLE, not merely by being unauthenticated. This
        // catches a guard that checks "is logged in" instead of "is admin".
        $this->signIn('john@example.com', 'john123');

        $this->sendRequest('GET', '/listusers');
        $this->assertStatus(404);

        $this->sendRequest('POST', '/storeuser');
        $this->assertStatus(404);

        $this->sendRequest('POST', '/updateuser/jane@example.com');
        $this->assertStatus(404);

        $this->sendRequest('POST', '/deleteuser/jane@example.com');
        $this->assertStatus(404);

        $this->sendRequest('POST', '/admin/users/jane@example.com/reset_mfa');
        $this->assertStatus(404);
    }

    public function testListUsers()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('GET', '/listusers');
        $this->assertOk();

        $this->assertResponseJsonHas([
            'data' => [
                [
                    'role' => 'guest',
                    'permissions' => [],
                    'homedir' => '/',
                    'username' => 'guest',
                    'name' => 'Guest',
                ],
                [
                    'role' => 'admin',
                    'permissions' => [],
                    'homedir' => '/',
                    'username' => 'admin@example.com',
                    'name' => 'Admin',
                ],
                [
                    'role' => 'user',
                    'permissions' => [],
                    'homedir' => '/john',
                    'username' => 'john@example.com',
                    'name' => 'John Doe',
                ],
                [
                    'role' => 'user',
                    'permissions' => [],
                    'homedir' => '/jane',
                    'username' => 'jane@example.com',
                    'name' => 'Jane Doe',
                ],
            ],
        ]);
    }

    public function testAddingNewUser()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/storeuser', [
            'name' => 'Mike Test',
            'username' => 'mike@example.com',
            'role' => 'user',
            'permissions' => [],
            'password' => 'pass123',
            'homedir' => '/john',
        ]);
        $this->assertOk();

        $this->assertResponseJsonHas([
            'data' => [
                'role' => 'user',
                'permissions' => [],
                'homedir' => '/john',
                'username' => 'mike@example.com',
                'name' => 'Mike Test',
            ],
        ]);
    }

    public function testAddingNewUserValidation()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/storeuser', [
            'name' => '',
            'username' => '',
            'role' => 'user',
            'permissions' => [],
            'password' => 'pass123',
            'homedir' => '',
        ]);
        $this->assertStatus(422);

        $this->sendRequest('POST', '/storeuser', [
            'name' => 'Mike Test',
            'username' => 'mike@example.com',
            'role' => 'bear',
            'permissions' => ['xxx'],
            'password' => 'pass123',
            'homedir' => '/john',
        ]);
        $this->assertStatus(422);
    }

    public function testUpdatingUser()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name' => 'Johnny Doe',
            'username' => 'john2@example.com',
            'role' => 'admin',
            'permissions' => ['read', 'write'],
            'homedir' => '/jane',
        ]);
        $this->assertOk();

        $this->assertResponseJsonHas([
            'data' => [
                'role' => 'admin',
                'permissions' => ['read', 'write'],
                'homedir' => '/jane',
                'username' => 'john2@example.com',
                'name' => 'Johnny Doe',
            ],
        ]);

        // Re-read /listusers to confirm the change PERSISTED (not just echoed
        // back in the update response): the old username is gone and the new
        // record carries the updated role/name.
        $this->sendRequest('GET', '/listusers');
        $users = $this->decodeResponseJson()['data'];
        $byName = array_column($users, null, 'username');
        $this->assertArrayNotHasKey('john@example.com', $byName);
        $this->assertArrayHasKey('john2@example.com', $byName);
        $this->assertSame('admin', $byName['john2@example.com']['role']);
        $this->assertSame('Johnny Doe', $byName['john2@example.com']['name']);
    }

    public function testDeletingUser()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/deleteuser/john@example.com');
        $this->assertOk();

        // Confirm the delete actually persisted — a no-op delete returning 200
        // would otherwise pass. The user is gone from /listusers and the total
        // count dropped by one (guest, admin, jane, multi remain).
        $this->sendRequest('GET', '/listusers');
        $users = $this->decodeResponseJson()['data'];
        $usernames = array_column($users, 'username');
        $this->assertNotContains('john@example.com', $usernames);
        $this->assertCount(4, $users);
    }

    public function testUpdatingNonExistingUser()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/updateuser/nonexisting@example.com');
        $this->assertStatus(422);
    }

    public function testUpdatingUserValidation()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name' => '',
            'username' => '',
            'homedir' => '',
        ]);
        $this->assertStatus(422);

        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name' => 'something',
            'username' => 'something',
            'homedir' => '/',
            'permissions' => ['xxx', 'write'],
        ]);
        $this->assertStatus(422);
    }

    public function testDeletingNonExistingUser()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/deleteuser/nonexisting@example.com');
        $this->assertStatus(422);
    }

    public function testAddingOrEditingUserWithUsernameThatIsAlreadyTaken()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/storeuser', [
            'name' => 'Mike Test',
            'username' => 'admin@example.com',
            'role' => 'user',
            'password' => '123',
            'permissions' => [],
            'homedir' => '/mike',
        ]);

        $this->assertStatus(422);

        $this->sendRequest('POST', '/updateuser/admin@example.com', [
            'name' => 'Admin',
            'username' => 'john@example.com',
            'role' => 'admin',
            'permissions' => [],
            'homedir' => '/',
        ]);

        $this->assertStatus(422);
    }

    // --------------------------------------------------------------------
    // Pins for the existing admin-input boundary behaviour. The multi-folder
    // refactor preserves these contracts; the tests guard the seams.
    // --------------------------------------------------------------------

    public function testStoreUserHomedirIsAdminPrefixJoined()
    {
        // Pin the exact admin-prefix join behaviour in storeUser
        // (AdminController.php ~line 87-91): the supplied homedir is
        // rtrim'd/ltrim'd and concatenated under the admin's homedir, with
        // NO `..` normalization at create time. Runtime safety for users
        // whose homedir contains `..` comes from Filesystem::applyPathPrefix
        // sandboxing every storage operation — not from this step. We pin
        // the existing string-concatenation shape so the multi-folder
        // refactor (which loops the same join over each homedirs[] element)
        // doesn't accidentally change the result.
        $this->signIn('admin@example.com', 'admin123');

        // Admin's homedir is '/'. Supplied homedir 'subdir' should land as
        // '/subdir'. Supplied homedir '/subdir' should also land as '/subdir'.
        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'Alpha',
            'username'    => 'alpha@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedir'     => 'alpha',
        ]);
        $this->assertOk();

        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'Beta',
            'username'    => 'beta@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedir'     => '/beta',
        ]);
        $this->assertOk();

        $this->sendRequest('GET', '/listusers');
        $rows = json_decode($this->response->getContent(), true)['data'];
        $byName = [];
        foreach ($rows as $u) {
            $byName[$u['username']] = $u['homedir'];
        }

        $this->assertSame('/alpha', $byName['alpha@example.com'] ?? null);
        $this->assertSame('/beta', $byName['beta@example.com'] ?? null);
    }

    public function testUpdateUserHomedirCanBeAnyString()
    {
        // updateUser does NOT apply the admin-prefix join — it accepts the
        // homedir field as-is. Pin this asymmetry so the multi-folder
        // refactor preserves it (storeUser joins, updateUser does not).
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name'        => 'John Doe',
            'username'    => 'john@example.com',
            'role'        => 'user',
            'permissions' => ['read', 'write', 'upload', 'download', 'batchdownload'],
            'homedir'     => '/relocated/explicit',
        ]);
        $this->assertOk();

        $this->assertResponseJsonHas([
            'data' => [
                'username' => 'john@example.com',
                'homedir'  => '/relocated/explicit',
            ],
        ]);
    }

    public function testNonAdminCannotBeAssignedFirmRootOnCreate()
    {
        // Admin's homedir is '/'. Supplying '/' (or empty-ish) for a non-admin
        // would resolve to the firm root after the join — must be rejected.
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'Root Grab',
            'username'    => 'rootgrab@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedir'     => '/',
        ]);
        $this->assertStatus(422);
        $this->assertResponseJsonHas(['data' => ['homedir' => 'Non-admin users must be assigned a specific subfolder, not the firm root.']]);
    }

    public function testNonAdminCannotBeRelocatedToFirmRootOnUpdate()
    {
        // updateUser stores homedirs verbatim, so the guard must catch a bare
        // '/' assignment to a non-admin here too.
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name'        => 'John Doe',
            'username'    => 'john@example.com',
            'role'        => 'user',
            'permissions' => ['read'],
            'homedir'     => '/',
        ]);
        $this->assertStatus(422);
        $this->assertResponseJsonHas(['data' => ['homedir' => 'Non-admin users must be assigned a specific subfolder, not the firm root.']]);
    }

    public function testAdminMayBeAssignedFirmRoot()
    {
        // The restriction is non-admin only: admins must still be grantable the
        // whole firm.
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name'        => 'John Doe',
            'username'    => 'john@example.com',
            'role'        => 'admin',
            'permissions' => ['read', 'write'],
            'homedir'     => '/',
        ]);
        $this->assertOk();
        $this->assertResponseJsonHas(['data' => ['homedir' => '/']]);
    }

    public function testNonAdminSubfolderIsAccepted()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'Client',
            'username'    => 'client@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedir'     => 'clientA',
        ]);
        $this->assertOk();
        $this->assertResponseJsonHas(['data' => ['homedir' => '/clientA']]);
    }

    public function testDuplicateEmailIsRejectedOnCreate()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'First',
            'username'    => 'first@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedir'     => '/first',
            'email'       => 'shared@example.test',
        ]);
        $this->assertOk();

        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'Second',
            'username'    => 'second@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedir'     => '/second',
            'email'       => 'shared@example.test',
        ]);
        $this->assertStatus(422);
        $this->assertResponseJsonHas(['data' => ['email' => 'Email already in use']]);
    }

    public function testUserMayKeepOwnEmailOnUpdate()
    {
        // Re-saving a user with the email it already owns must not be flagged
        // as a duplicate.
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name'        => 'John Doe',
            'username'    => 'john@example.com',
            'role'        => 'user',
            'permissions' => ['read'],
            'homedir'     => '/john',
            'email'       => 'john.owns@example.test',
        ]);
        $this->assertOk();

        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name'        => 'John Doe',
            'username'    => 'john@example.com',
            'role'        => 'user',
            'permissions' => ['read'],
            'homedir'     => '/john',
            'email'       => 'john.owns@example.test',
        ]);
        $this->assertOk();
    }

    public function testListUsersShapeIncludesHomedirField()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('GET', '/listusers');
        $this->assertOk();

        $rows = json_decode($this->response->getContent(), true)['data'];
        $this->assertNotEmpty($rows);
        foreach ($rows as $u) {
            $this->assertArrayHasKey('homedir', $u, 'listUsers row missing homedir key');
            $this->assertArrayHasKey('homedirs', $u, 'listUsers row missing homedirs key (Phase 2)');
            $this->assertArrayHasKey('username', $u);
            $this->assertArrayHasKey('role', $u);
            $this->assertArrayHasKey('name', $u);
            $this->assertArrayHasKey('permissions', $u);
        }
    }

    // --------------------------------------------------------------------
    // Phase 4: storeUser/updateUser accept `homedirs[]` while preserving
    // back-compat for the legacy `homedir` scalar payload shape.
    // --------------------------------------------------------------------

    public function testStoreUserAcceptsHomedirsArray()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'Multi Maker',
            'username'    => 'mm@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedirs'    => ['mfolderA', '/mfolderB'],
        ]);
        $this->assertOk();

        $this->sendRequest('GET', '/listusers');
        $rows = json_decode($this->response->getContent(), true)['data'];
        $mm = null;
        foreach ($rows as $u) {
            if ($u['username'] === 'mm@example.com') $mm = $u;
        }
        $this->assertNotNull($mm);
        // Admin is at '/' — join is /<each>. Both shapes ('plain' and
        // '/leading-slash') normalise to /plain and /leading-slash.
        $this->assertSame(['/mfolderA', '/mfolderB'], $mm['homedirs']);
        // Back-compat scalar key is the first element.
        $this->assertSame('/mfolderA', $mm['homedir']);
    }

    public function testStoreUserAcceptsLegacyHomedirString()
    {
        $this->signIn('admin@example.com', 'admin123');

        // Legacy frontend payload — single string. Must still work through
        // Phase 10.
        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'Legacy',
            'username'    => 'legacy@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedir'     => '/legacy',
        ]);
        $this->assertOk();

        $this->sendRequest('GET', '/listusers');
        $rows = json_decode($this->response->getContent(), true)['data'];
        $legacy = null;
        foreach ($rows as $u) {
            if ($u['username'] === 'legacy@example.com') $legacy = $u;
        }
        $this->assertNotNull($legacy);
        $this->assertSame(['/legacy'], $legacy['homedirs']);
    }

    public function testStoreUserRejectsEmptyHomedirs()
    {
        $this->signIn('admin@example.com', 'admin123');

        // Empty array
        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'No Folders',
            'username'    => 'nf@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedirs'    => [],
        ]);
        $this->assertStatus(422);

        // Blank-only strings
        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'No Folders',
            'username'    => 'nf@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedirs'    => ['', '   ', '  '],
        ]);
        $this->assertStatus(422);

        // Missing both keys entirely
        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'No Folders',
            'username'    => 'nf@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
        ]);
        $this->assertStatus(422);
    }

    public function testStoreUserAdminPrefixJoinAppliesToEachElement()
    {
        // Pin: each element of homedirs gets the same admin-prefix join
        // that the pre-refactor scalar storeUser did to its single input.
        // Admin is at '/' in the test fixture; supplied 'x' becomes '/x'.
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'JoinTest',
            'username'    => 'jt@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'pass123',
            'homedirs'    => ['a', 'b', 'c'],
        ]);
        $this->assertOk();

        $this->sendRequest('GET', '/listusers');
        $rows = json_decode($this->response->getContent(), true)['data'];
        foreach ($rows as $u) {
            if ($u['username'] === 'jt@example.com') {
                $this->assertSame(['/a', '/b', '/c'], $u['homedirs']);
                return;
            }
        }
        $this->fail('jt@example.com not found in listUsers');
    }

    public function testUpdateUserHomedirsArrayPath()
    {
        // Move john from single-folder to multi-folder via updateUser.
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name'        => 'John Doe',
            'username'    => 'john@example.com',
            'role'        => 'user',
            'permissions' => ['read', 'write', 'upload', 'download', 'batchdownload'],
            'homedirs'    => ['/jext1', '/jext2'],
        ]);
        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'username' => 'john@example.com',
                'homedirs' => ['/jext1', '/jext2'],
                // back-compat scalar = first element
                'homedir'  => '/jext1',
            ],
        ]);
    }

    public function testUpdateUserNoPrefixJoin()
    {
        // Asymmetry pin: updateUser stores the supplied value verbatim,
        // unlike storeUser which prefixes with the admin's homedir.
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name'        => 'John Doe',
            'username'    => 'john@example.com',
            'role'        => 'user',
            'permissions' => ['read', 'write', 'upload', 'download', 'batchdownload'],
            'homedirs'    => ['raw-no-leading-slash'],
        ]);
        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'homedirs' => ['raw-no-leading-slash'],
            ],
        ]);
    }

    // --------------------------------------------------------------------
    // Password round-trips: assert the password an admin SETS actually
    // authenticates afterward, and that an admin-CHANGED password takes
    // effect (new works, old stops working). The CRUD-shape tests above
    // only check the response payload, never that the credential works.
    // --------------------------------------------------------------------

    public function testAdminCreatedUserCanLogInWithSetPassword()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/storeuser', [
            'name'        => 'Pat New',
            'username'    => 'pat@example.com',
            'role'        => 'user',
            'permissions' => [],
            'password'    => 'patsecret1',
            'homedir'     => '/pat',
        ]);
        $this->assertOk();

        // The set password must actually authenticate.
        $this->signOut();
        $this->sendRequest('POST', '/login', [
            'username' => 'pat@example.com',
            'password' => 'patsecret1',
        ]);
        $this->assertOk();
        $this->assertResponseJsonHas(['data' => ['username' => 'pat@example.com']]);

        // A wrong password must not.
        $this->signOut();
        $this->sendRequest('POST', '/login', [
            'username' => 'pat@example.com',
            'password' => 'not-the-password',
        ]);
        $this->assertStatus(422);
    }

    public function testAdminChangingUserPasswordViaUpdateTakesEffect()
    {
        $this->signIn('admin@example.com', 'admin123');

        // updateUser applies a new password only when the field is non-empty.
        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name'        => 'John Doe',
            'username'    => 'john@example.com',
            'role'        => 'user',
            'permissions' => ['read', 'write'],
            'homedir'     => '/john',
            'password'    => 'johns-new-pw',
        ]);
        $this->assertOk();

        // New password works.
        $this->signOut();
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'johns-new-pw',
        ]);
        $this->assertOk();
        $this->assertResponseJsonHas(['data' => ['username' => 'john@example.com']]);

        // Old password no longer works.
        $this->signOut();
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ]);
        $this->assertStatus(422);
    }

    public function testAdminUpdateWithoutPasswordLeavesItUnchanged()
    {
        $this->signIn('admin@example.com', 'admin123');

        // Omitting the password field must preserve the existing credential.
        $this->sendRequest('POST', '/updateuser/john@example.com', [
            'name'        => 'Johnny',
            'username'    => 'john@example.com',
            'role'        => 'user',
            'permissions' => ['read', 'write'],
            'homedir'     => '/john',
        ]);
        $this->assertOk();

        $this->signOut();
        $this->sendRequest('POST', '/login', [
            'username' => 'john@example.com',
            'password' => 'john123',
        ]);
        $this->assertOk();
        $this->assertResponseJsonHas(['data' => ['username' => 'john@example.com']]);
    }

    // --------------------------------------------------------------------
    // Folder-access audit: the inverse view of listUsers (folder -> users),
    // with inherited access from a parent/root homedir surfaced.
    // --------------------------------------------------------------------

    public function testFolderAccessAuditIsAdminOnly()
    {
        $this->signOut();
        $this->sendRequest('GET', '/admin/folder-access-audit');
        $this->assertStatus(404);

        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('GET', '/admin/folder-access-audit');
        $this->assertStatus(404);
    }

    public function testAuditLogIsAdminOnly()
    {
        // The audit log exposes PII (client paths + source IPs); the route's
        // roles=>['admin'] gate is the sole server-side authorization boundary.
        $this->signOut();
        $this->sendRequest('GET', '/admin/audit-log');
        $this->assertStatus(404);

        $this->signIn('john@example.com', 'john123');
        $this->sendRequest('GET', '/admin/audit-log');
        $this->assertStatus(404);
    }

    public function testFolderAccessAuditListsAssignedFolders()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('GET', '/admin/folder-access-audit');
        $this->assertOk();

        $data = $this->decodeResponseJson()['data'];
        $this->assertSame('/', $data['separator']);

        $byPath = array_column($data['folders'], null, 'path');
        // Every assigned homedir from the fixture shows up as a row.
        foreach (['/', '/john', '/jane', '/multiA', '/multiB'] as $p) {
            $this->assertArrayHasKey($p, $byPath, "missing folder row {$p}");
        }
        // Concrete access sets (guest excluded): only the root admin reaches
        // '/', while '/john' is the root admin (inherited) plus john (direct).
        $this->assertSame(['admin@example.com'], array_column($byPath['/']['access'], 'username'));
        $this->assertSame(1, $byPath['/']['user_count']);
        $this->assertSame(2, $byPath['/john']['user_count']);
        // user_count stays in sync with the access list it summarises.
        foreach ($data['folders'] as $folder) {
            $this->assertSame(count($folder['access']), $folder['user_count']);
        }
    }

    public function testFolderAccessAuditSurfacesInheritedAccess()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('GET', '/admin/folder-access-audit');
        $this->assertOk();

        $byPath = array_column($this->decodeResponseJson()['data']['folders'], null, 'path');
        $john = array_column($byPath['/john']['access'], null, 'username');

        // Direct owner.
        $this->assertArrayHasKey('john@example.com', $john);
        $this->assertFalse($john['john@example.com']['inherited']);
        $this->assertSame('/john', $john['john@example.com']['granted_by']);

        // Root admin reaches it by inheritance.
        $this->assertArrayHasKey('admin@example.com', $john);
        $this->assertTrue($john['admin@example.com']['inherited']);
        $this->assertSame('/', $john['admin@example.com']['granted_by']);

        // A sibling-folder user must NOT appear.
        $this->assertArrayNotHasKey('jane@example.com', $john);

        // The anonymous guest pseudo-account is excluded from the audit.
        $this->assertArrayNotHasKey('guest', $john);
    }

    public function testFolderAccessAuditExcludesGuestAccount()
    {
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('GET', '/admin/folder-access-audit');
        $this->assertOk();

        // The guest's homedir is '/', so without the exclusion it would appear
        // (inherited) on every folder. It must appear on none.
        foreach ($this->decodeResponseJson()['data']['folders'] as $folder) {
            $usernames = array_column($folder['access'], 'username');
            $this->assertNotContains('guest', $usernames, "guest leaked into {$folder['path']}");
        }
    }

    public function testFolderAccessAuditListsMultipleOwnersOfOneFolder()
    {
        $this->signIn('admin@example.com', 'admin123');

        // A second user assigned the very same folder as john.
        $this->sendRequest('POST', '/storeuser', [
            'name' => 'Second John',
            'username' => 'john2@example.com',
            'role' => 'user',
            'permissions' => [],
            'password' => 'pass123',
            'homedir' => '/john',
        ]);
        $this->assertOk();

        $this->sendRequest('GET', '/admin/folder-access-audit');
        $this->assertOk();
        $folders = $this->decodeResponseJson()['data']['folders'];

        // Still a single /john row, not one per owner.
        $johnRows = array_values(array_filter($folders, function ($f) {
            return $f['path'] === '/john';
        }));
        $this->assertCount(1, $johnRows);

        // Both direct owners are listed under it.
        $direct = array_column(array_filter($johnRows[0]['access'], function ($a) {
            return ! $a['inherited'];
        }), 'username');
        $this->assertContains('john@example.com', $direct);
        $this->assertContains('john2@example.com', $direct);
    }

    public function testFolderAccessAuditCollapsesTrailingSeparatorDuplicates()
    {
        $this->signIn('admin@example.com', 'admin123');

        // Two users on the same folder, one with a trailing separator.
        $this->sendRequest('POST', '/storeuser', [
            'name' => 'Clean', 'username' => 'clean@example.com', 'role' => 'user',
            'permissions' => [], 'password' => 'pass123', 'homedir' => 'clientA',
        ]);
        $this->assertOk();
        $this->sendRequest('POST', '/storeuser', [
            'name' => 'Slashy', 'username' => 'slashy@example.com', 'role' => 'user',
            'permissions' => [], 'password' => 'pass123', 'homedir' => 'clientA/',
        ]);
        $this->assertOk();

        $this->sendRequest('GET', '/admin/folder-access-audit');
        $this->assertOk();
        $folders = $this->decodeResponseJson()['data']['folders'];

        // '/clientA' and '/clientA/' fold into exactly one row.
        $rows = array_values(array_filter($folders, function ($f) {
            return $f['path'] === '/clientA';
        }));
        $this->assertCount(1, $rows);

        $byUser = array_column($rows[0]['access'], null, 'username');
        $this->assertArrayHasKey('clean@example.com', $byUser);
        $this->assertArrayHasKey('slashy@example.com', $byUser);
        // Both own the folder directly. slashy's homedir carries a trailing
        // slash ('/clientA/'), so 'inherited' must be computed on the NORMALISED
        // path — a raw string compare ('/clientA/' !== 'clientA') would wrongly
        // flag slashy as inherited.
        $this->assertFalse($byUser['clean@example.com']['inherited']);
        $this->assertFalse($byUser['slashy@example.com']['inherited']);
        $this->assertSame('/clientA/', $byUser['slashy@example.com']['granted_by']);
    }

    public function testFolderAccessAuditPathModeResolvesAgainstActiveHomedir()
    {
        // Path mode must resolve a browsed path against the admin's ACTIVE
        // homedir, not blindly homedirs[0]. Set up a multi-folder admin, select
        // the SECOND folder, then inspect a subpath under it.
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/storeuser', [
            'name' => 'Multi Admin', 'username' => 'madmin@example.com',
            'role' => 'admin', 'permissions' => ['read'],
            'password' => 'pass123', 'homedirs' => ['/teamA', '/teamB'],
        ]);
        $this->assertOk();

        // A user living under /teamB so the resolved folder has a direct owner.
        $this->sendRequest('POST', '/storeuser', [
            'name' => 'Bob', 'username' => 'bob@example.com',
            'role' => 'user', 'permissions' => ['read'],
            'password' => 'pass123', 'homedir' => '/teamB/project',
        ]);
        $this->assertOk();

        $this->signIn('madmin@example.com', 'pass123');
        // Select the SECOND homedir as active (active != homedirs[0]).
        $this->sendRequest('POST', '/selectfolder', ['homedir' => '/teamB']);
        $this->assertOk();

        // The browse tree shows '/project' relative to the active homedir (/teamB).
        $this->sendRequest('GET', '/admin/folder-access-audit&path='.rawurlencode('/project'));
        $this->assertOk();

        $folder = $this->decodeResponseJson()['data']['folders'][0];
        // Resolved against /teamB (active), NOT /teamA (homedirs[0]).
        $this->assertSame('/teamB/project', $folder['path']);

        $byUser = array_column($folder['access'], null, 'username');
        $this->assertArrayHasKey('bob@example.com', $byUser);
        $this->assertFalse($byUser['bob@example.com']['inherited']);
    }

    public function testFolderAccessAuditPathModeInspectsArbitraryFolder()
    {
        $this->signIn('admin@example.com', 'admin123');

        // A subfolder of /john that is not itself an assigned homedir.
        $this->sendRequest('GET', '/admin/folder-access-audit&path='.rawurlencode('/john/2024'));
        $this->assertOk();

        $data = $this->decodeResponseJson()['data'];
        $this->assertCount(1, $data['folders']);
        $folder = $data['folders'][0];
        $this->assertSame('/john/2024', $folder['path']);

        $byUser = array_column($folder['access'], null, 'username');
        // john reaches it (inherited from /john); admin reaches it (inherited from /).
        $this->assertArrayHasKey('john@example.com', $byUser);
        $this->assertTrue($byUser['john@example.com']['inherited']);
        $this->assertSame('/john', $byUser['john@example.com']['granted_by']);
        $this->assertArrayHasKey('admin@example.com', $byUser);
        $this->assertTrue($byUser['admin@example.com']['inherited']);
        // A sibling-folder user cannot reach it.
        $this->assertArrayNotHasKey('jane@example.com', $byUser);
    }
}
