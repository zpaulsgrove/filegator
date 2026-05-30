<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Storage\Adapters\FilegatorFtp;
use Tests\TestCase;

/**
 * Unit-tests the pure `ls -l` parsing in FilegatorFtp without a live FTP
 * server. The adapter constructor only stores config (it connects lazily),
 * and the after-normalize hooks are pure string parsing, so they can be
 * invoked directly.
 *
 * @internal
 */
class FilegatorFtpTest extends TestCase
{
    private function adapter(): FilegatorFtp
    {
        return new FilegatorFtp([
            'host' => 'localhost',
            'username' => 'u',
            'password' => 'p',
            'root' => '/',
        ]);
    }

    public function testAfterNormalizeUnixObjectParsesFilePermissions()
    {
        $ftp = $this->adapter();
        $result = $this->invokeMethod($ftp, 'afterNormalizeUnixObject', [
            ['type' => 'file', 'path' => 'file1.txt'],
            '-rw-r--r--   1 ftp      ftp           409 Aug 19 09:01 file1.txt',
            '',
        ]);

        $this->assertSame('644', $result['permissions']);
    }

    public function testAfterNormalizeUnixObjectParsesDirectoryPermissions()
    {
        $ftp = $this->adapter();
        $result = $this->invokeMethod($ftp, 'afterNormalizeUnixObject', [
            ['type' => 'dir', 'path' => 'sub'],
            'drwxr-xr-x   2 ftp      ftp          4096 Aug 19 09:01 sub',
            '',
        ]);

        $this->assertSame('755', $result['permissions']);
    }

    public function testAfterNormalizeUnixObjectParsesWorldWritablePermissions()
    {
        $ftp = $this->adapter();
        $result = $this->invokeMethod($ftp, 'afterNormalizeUnixObject', [
            ['type' => 'file', 'path' => 'exec.sh'],
            '-rwxrwxrwx   1 ftp      ftp            10 Aug 19 09:01 exec.sh',
            '',
        ]);

        $this->assertSame('777', $result['permissions']);
    }

    public function testAfterNormalizeWindowsObjectAlwaysReturns777()
    {
        $ftp = $this->adapter();
        $result = $this->invokeMethod($ftp, 'afterNormalizeWindowsObject', [
            ['type' => 'file', 'path' => 'a.txt'],
            '08-19-21  09:01AM       409 a.txt',
            '',
        ]);

        $this->assertSame(777, $result['permissions']);
    }
}
