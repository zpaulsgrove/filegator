<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Mfa\MfaSecretCrypto;
use Tests\TestCase;

/**
 * @internal
 */
class MfaSecretCryptoTest extends TestCase
{
    protected $keyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetTempDir();
        $this->keyPath = TEST_TMP_PATH.'mfa_test.key';
        if (file_exists($this->keyPath)) {
            unlink($this->keyPath);
        }
    }

    protected function makeCrypto(?string $path = null): MfaSecretCrypto
    {
        $c = new MfaSecretCrypto();
        $c->init(['key_path' => $path ?? $this->keyPath]);
        return $c;
    }

    public function testRoundTripEncryptDecrypt()
    {
        $crypto = $this->makeCrypto();
        $plain = 'JBSWY3DPEHPK3PXP';

        $cipher = $crypto->encrypt($plain);
        $this->assertStringStartsWith('v1$', $cipher);
        $this->assertNotSame($plain, $cipher);
        $this->assertSame($plain, $crypto->decrypt($cipher));
    }

    public function testDecryptOfMalformedReturnsNull()
    {
        $crypto = $this->makeCrypto();
        $this->assertNull($crypto->decrypt('not-a-versioned-string'));
        $this->assertNull($crypto->decrypt('v1$totally-not-base64!@#'));
        $this->assertNull($crypto->decrypt('v1$'.base64_encode('too-short')));
        $this->assertNull($crypto->decrypt('v2$'.base64_encode(str_repeat('A', 64))));
    }

    public function testDecryptWithWrongKeyReturnsNull()
    {
        $crypto1 = $this->makeCrypto();
        $cipher = $crypto1->encrypt('JBSWY3DPEHPK3PXP');

        // Different keyfile path → different key → decryption MAC fails.
        $otherPath = TEST_TMP_PATH.'mfa_other.key';
        if (file_exists($otherPath)) unlink($otherPath);
        $crypto2 = $this->makeCrypto($otherPath);
        $this->assertNull($crypto2->decrypt($cipher));
    }

    public function testKeyFileCreatedWith0600Perms()
    {
        $crypto = $this->makeCrypto();
        $crypto->encrypt('seed-the-keyfile-creation');

        $this->assertFileExists($this->keyPath);
        $perms = fileperms($this->keyPath) & 0777;
        $this->assertSame(0600, $perms, sprintf('keyfile perms were 0%o, expected 0600', $perms));
    }

    public function testKeyIsStablePerProcess()
    {
        $crypto = $this->makeCrypto();
        $cipherA = $crypto->encrypt('seed');
        // Re-create the service — should read the persisted key, not regenerate.
        $crypto2 = $this->makeCrypto();
        $this->assertSame('seed', $crypto2->decrypt($cipherA));
    }

    public function testIsEncryptedDetectsPrefix()
    {
        $crypto = $this->makeCrypto();
        $this->assertTrue($crypto->isEncrypted('v1$abcdef'));
        $this->assertFalse($crypto->isEncrypted('JBSWY3DPEHPK3PXP'));
        $this->assertFalse($crypto->isEncrypted(''));
    }

    public function testInitWithoutKeyPathThrows()
    {
        $crypto = new MfaSecretCrypto();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MfaSecretCrypto requires a `key_path` config entry');
        $crypto->init([]);
    }

    public function testInitWithEmptyKeyPathThrows()
    {
        $crypto = new MfaSecretCrypto();
        $this->expectException(\RuntimeException::class);
        $crypto->init(['key_path' => '']);
    }

    /**
     * A keyfile that exists but holds the wrong number of bytes is treated
     * as malformed: readKeyFromDisk() exhausts its retry/backoff loop and
     * throws. encrypt() lets that propagate (no try/catch on the create path).
     */
    public function testEncryptThrowsOnMalformedKeyFile()
    {
        $badPath = TEST_TMP_PATH.'mfa_malformed_encrypt.key';
        // Wrong length on purpose (not SODIUM_CRYPTO_SECRETBOX_KEYBYTES).
        file_put_contents($badPath, 'too-short-key-bytes');

        $crypto = $this->makeCrypto($badPath);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('is missing or malformed');
            $crypto->encrypt('whatever');
        } finally {
            @unlink($badPath);
        }
    }

    /**
     * decrypt() swallows the loadOrCreateKey() failure and returns null so
     * callers treat the secret as unrecoverable. Drive the same malformed
     * keyfile through decrypt(): it must hit the catch path, not throw.
     */
    public function testDecryptReturnsNullWhenKeyFileMalformed()
    {
        // First, produce a valid ciphertext using a good keyfile.
        $goodCrypto = $this->makeCrypto();
        $cipher = $goodCrypto->encrypt('JBSWY3DPEHPK3PXP');
        $this->assertStringStartsWith('v1$', $cipher);

        // Now point a fresh service at a malformed keyfile so key load throws
        // from inside decrypt()'s try block.
        $badPath = TEST_TMP_PATH.'mfa_malformed_decrypt.key';
        file_put_contents($badPath, 'short');

        $crypto = $this->makeCrypto($badPath);
        try {
            $this->assertNull($crypto->decrypt($cipher));
        } finally {
            @unlink($badPath);
        }
    }

    /**
     * Spawn N parallel PHP subprocesses, each of which creates a fresh
     * MfaSecretCrypto pointed at the same missing key file and prints the
     * resulting key bytes. Assert that exactly one keyfile exists at the
     * end (proves no rewrite race) and all processes see the same key.
     */
    public function testKeyfileRaceAtomicCreate()
    {
        $racePath = TEST_TMP_PATH.'mfa_race.key';
        if (file_exists($racePath)) unlink($racePath);

        $bootstrap = __DIR__.'/../../../vendor/autoload.php';
        $script = '<?php
            require '.var_export($bootstrap, true).';
            $c = new Filegator\Services\Mfa\MfaSecretCrypto();
            $c->init(["key_path" => '.var_export($racePath, true).']);
            // Force key load via an encrypt round-trip.
            $cipher = $c->encrypt("race");
            echo bin2hex(file_get_contents('.var_export($racePath, true).'));
        ';
        $scriptPath = TEST_TMP_PATH.'race_runner.php';
        file_put_contents($scriptPath, $script);

        $procs = [];
        $pipes = [];
        $php = (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
        for ($i = 0; $i < 5; $i++) {
            $procs[$i] = proc_open(
                [$php, $scriptPath],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i]
            );
        }

        $keys = [];
        foreach ($procs as $i => $p) {
            $keys[$i] = trim(stream_get_contents($pipes[$i][1]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($p);
        }

        // All five processes saw the same key.
        $this->assertCount(1, array_unique($keys), 'subprocesses saw different keys — race created multiple keyfiles');
        $this->assertNotEmpty($keys[0]);

        // Final keyfile is exactly one key. Catches "two writes appended"
        // regression where a non-atomic create-or-write would double-write.
        $this->assertSame(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            filesize($racePath),
            'keyfile is not exactly one key — non-atomic create may have appended'
        );

        // Perms are 0600 even on the race-fallback path.
        $perms = fileperms($racePath) & 0777;
        $this->assertSame(0600, $perms, sprintf('race-created keyfile perms were 0%o, expected 0600', $perms));

        // Cleanup
        @unlink($scriptPath);
        @unlink($racePath);
    }
}
