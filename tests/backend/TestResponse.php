<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests;

trait TestResponse
{
    public function assertResponseJsonHas(array $data, $strict = false)
    {
        self::assertArraySubset(
            $data,
            $this->decodeResponseJson(),
            $strict,
            $this->assertJsonMessage($data)
        );

        return $this;
    }

    public function assertResponseJsonFragment(array $data)
    {
        $actual = json_encode((array) $this->decodeResponseJson());

        foreach ($data as $key => $value) {
            $expected = $this->jsonSearchStrings($key, $value);

            $this->assertTrue(
                $this->str_contains($actual, $expected),
                'Unable to find JSON fragment: '.PHP_EOL.PHP_EOL.
                '['.json_encode([$key => $value]).']'.PHP_EOL.PHP_EOL.
                'within'.PHP_EOL.PHP_EOL.
                "[{$actual}]."
            );
        }

        return $this;
    }

    public function decodeResponseJson($key = null)
    {
        $decodedResponse = json_decode($this->response->getContent(), true);

        if (is_null($decodedResponse) || $decodedResponse === false) {
            $this->fail('Invalid JSON was returned from the route.');
        }

        return $decodedResponse;
    }

    /**
     * Recursive "array contains subset" assertion. PHPUnit's built-in
     * assertArraySubset() and the ArraySubset constraint were removed in
     * PHPUnit 9, so we keep a self-contained equivalent here. With
     * $checkForObjectIdentity (strict) values are compared with ===.
     */
    public static function assertArraySubset($subset, $array, bool $checkForObjectIdentity = false, string $message = ''): void
    {
        self::assertTrue(
            self::isArraySubset($subset, $array, $checkForObjectIdentity),
            $message !== '' ? $message : 'Failed asserting that an array contains the expected subset.'
        );
    }

    private static function isArraySubset($subset, $array, bool $strict): bool
    {
        if (! (\is_array($subset) || $subset instanceof \ArrayAccess)
            || ! (\is_array($array) || $array instanceof \ArrayAccess)) {
            return false;
        }

        foreach ($subset as $key => $value) {
            if (! isset($array[$key]) && ! (\is_array($array) && \array_key_exists($key, $array))) {
                return false;
            }

            if (\is_array($value)) {
                if (! self::isArraySubset($value, $array[$key], $strict)) {
                    return false;
                }
            } elseif ($strict ? $array[$key] !== $value : $array[$key] != $value) {
                return false;
            }
        }

        return true;
    }

    public function getStatusCode()
    {
        return $this->response->getStatusCode();
    }

    public function assertStatus($status)
    {
        $this->assertEquals(
            $status,
            $this->getStatusCode()
        );

        return $this;
    }

    public function assertOk()
    {
        $this->assertTrue(
            $this->isOk(),
            'Response status code ['.$this->getStatusCode().'] does not match expected 200 status code.'
        );

        return $this;
    }

    public function assertUnprocessable()
    {
        $this->assertTrue(
            $this->isUnprocessable(),
            'Response status code ['.$this->getStatusCode().'] does not match expected 422 status code.'
        );

        return $this;
    }

    public function isOk(): bool
    {
        return 200 === $this->getStatusCode();
    }

    public function isUnprocessable(): bool
    {
        return 422 === $this->getStatusCode();
    }

    public function str_contains($haystack, $needles)
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function assertJsonMessage(array $data)
    {
        $expected = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $actual = json_encode($this->decodeResponseJson(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return 'Unable to find JSON: '.PHP_EOL.PHP_EOL.
            "[{$expected}]".PHP_EOL.PHP_EOL.
            'within response JSON:'.PHP_EOL.PHP_EOL.
            "[{$actual}].".PHP_EOL.PHP_EOL;
    }

    protected function jsonSearchStrings($key, $value)
    {
        $needle = substr(json_encode([$key => $value]), 1, -1);

        return [
            $needle.']',
            $needle.'}',
            $needle.',',
        ];
    }
}
