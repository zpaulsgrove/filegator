<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Mfa;

use Filegator\Utils\PasswordHash;

class BackupCodeGenerator
{
    use PasswordHash;

    const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // excludes 0,O,1,I

    /**
     * Generate $count plaintext codes of $length characters, split into two
     * groups by a hyphen (e.g. the default length 10 yields XXXXX-XXXXX).
     *
     * @return string[]
     */
    public static function generate(int $count = 10, int $length = 10): array
    {
        // Derive the split point from $length so the grouping tracks the
        // requested length. A hardcoded split at 5 only produced the documented
        // XXXXX-XXXXX shape for length 10 and emitted malformed codes (empty or
        // lopsided groups, a trailing hyphen) for any other length.
        $split = intdiv($length, 2);

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = '';
            for ($j = 0; $j < $length; $j++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, $split).'-'.substr($raw, $split);
        }
        return $codes;
    }

    /**
     * Hash a list of plaintext backup codes for storage.
     *
     * @param  string[] $codes
     * @return string[]
     */
    public static function hashAll(array $codes): array
    {
        return array_map(static fn ($c) => self::hashPassword(self::normalize($c)), $codes);
    }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
    }
}
