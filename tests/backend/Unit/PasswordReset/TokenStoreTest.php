<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit\PasswordReset;

use Filegator\Services\PasswordReset\TokenStore;
use Tests\TestCase;

/**
 * Direct unit coverage for the single-use password-reset token store. The
 * feature suite exercises this through HTTP, but only asserts end-to-end
 * outcomes; these tests pin the internal invariants (single-use barrier,
 * expiry boundaries, prior-token invalidation, gc cutoff) that mutation
 * testing showed were not actually checked.
 *
 * @internal
 */
class TokenStoreTest extends TestCase
{
    private $file;

    private $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = TEST_TMP_PATH.'tokenstore_test.json';
        if (file_exists($this->file)) {
            @unlink($this->file);
        }
        $this->store = new TokenStore($this->file);
    }

    private function rawRows(): array
    {
        return json_decode((string) file_get_contents($this->file), true) ?: [];
    }

    public function testAddThenFindReturnsTheStoredRow()
    {
        $this->store->add('alice', 'hashA', 3600, '1.1.1.1');

        $row = $this->store->find('hashA');
        $this->assertIsArray($row);
        $this->assertSame('alice', $row['username']);
        $this->assertSame('1.1.1.1', $row['ip']);
        $this->assertFalse($row['used']);
    }

    public function testFindReturnsNullForUnknownHash()
    {
        $this->store->add('alice', 'hashA', 3600, '1.1.1.1');

        $this->assertNull($this->store->find('nope'));
    }

    public function testFindReturnsNullForUsedToken()
    {
        $this->store->add('alice', 'hashA', 3600, '1.1.1.1');
        $this->assertTrue($this->store->markUsed('hashA'));

        $this->assertNull($this->store->find('hashA'));
    }

    public function testFindReturnsNullForExpiredToken()
    {
        // Negative ttl => expires in the past, but still within the gc window.
        $this->store->add('alice', 'hashA', -10, '1.1.1.1');

        $this->assertNull($this->store->find('hashA'));
    }

    public function testFindAcceptsTokenExpiringExactlyNow()
    {
        // expires == now must NOT be treated as expired (find uses `< now`).
        $this->store->add('alice', 'edge', 0, '1.1.1.1');

        $this->assertIsArray($this->store->find('edge'));
    }

    public function testMarkUsedReturnsTrueFirstTimeAndFalseSecond()
    {
        $this->store->add('alice', 'hashA', 3600, '1.1.1.1');

        // The single-use barrier: exactly one caller wins.
        $this->assertTrue($this->store->markUsed('hashA'));
        $this->assertFalse($this->store->markUsed('hashA'));
    }

    public function testMarkUsedPersistsTheUsedFlag()
    {
        $this->store->add('alice', 'hashA', 3600, '1.1.1.1');
        $this->store->markUsed('hashA');

        $rows = $this->rawRows();
        $this->assertTrue($rows[0]['used']);
    }

    public function testMarkUsedReturnsFalseForUnknownHash()
    {
        $this->store->add('alice', 'hashA', 3600, '1.1.1.1');

        $this->assertFalse($this->store->markUsed('nope'));
    }

    public function testMarkUsedReturnsFalseForExpiredToken()
    {
        $this->store->add('alice', 'hashA', -10, '1.1.1.1');

        $this->assertFalse($this->store->markUsed('hashA'));
    }

    public function testMarkUsedAcceptsTokenExpiringExactlyNow()
    {
        // expires == now must still be consumable: markUsed() uses `< now`, so a
        // token whose expiry is exactly the current second is not yet expired.
        // Pins the boundary so `<` cannot weaken to `<=`.
        $this->store->add('alice', 'edge', 0, '1.1.1.1');

        $this->assertTrue($this->store->markUsed('edge'));
    }

    public function testAddInvalidatesPriorUnusedTokenForSameUser()
    {
        $this->store->add('alice', 'first', 3600, '1.1.1.1');
        $this->store->add('alice', 'second', 3600, '1.1.1.1');

        // Issuing a new token must invalidate the previous unused one.
        $this->assertNull($this->store->find('first'));
        $this->assertIsArray($this->store->find('second'));
    }

    public function testAddDoesNotInvalidateOtherUsersTokens()
    {
        $this->store->add('alice', 'aliceTok', 3600, '1.1.1.1');
        $this->store->add('bob', 'bobTok', 3600, '2.2.2.2');

        // bob's reset must not disturb alice's live token.
        $this->assertIsArray($this->store->find('aliceTok'));
        $this->assertIsArray($this->store->find('bobTok'));
    }

    public function testGcKeepsRowAtExactlyTheCutoffAndDropsOlder()
    {
        $now = 1_000_000_000;
        $cutoff = $now - 86400;
        $rows = [
            ['token_hash' => 'keep', 'username' => 'a', 'expires' => $cutoff, 'used' => false],
            ['token_hash' => 'drop', 'username' => 'a', 'expires' => $cutoff - 1, 'used' => false],
        ];

        $kept = $this->invokeMethod($this->store, 'gc', [$rows, $now]);

        $hashes = array_column($kept, 'token_hash');
        $this->assertContains('keep', $hashes, 'row expiring exactly at the cutoff must be kept (>=)');
        $this->assertNotContains('drop', $hashes, 'row older than the 24h cutoff must be dropped');
    }

    public function testGcReindexesSurvivors()
    {
        $now = 1_000_000_000;
        $rows = [
            ['token_hash' => 'old', 'expires' => $now - 90000, 'used' => false],
            ['token_hash' => 'new', 'expires' => $now + 10, 'used' => false],
        ];

        $kept = $this->invokeMethod($this->store, 'gc', [$rows, $now]);

        // array_values: survivors must be a 0-indexed list, not preserve key 1.
        $this->assertArrayHasKey(0, $kept);
        $this->assertSame('new', $kept[0]['token_hash']);
    }
}
