<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Fakes;

use Filegator\Services\Storage\Filesystem;

/**
 * A Filesystem whose delete reports failure by RETURNING false (as the
 * SFTP/FTP adapters can) rather than throwing like the Local adapter. Lets a
 * feature test prove the controllers skip the audit record when a storage op
 * reports failure — the "record only what actually happened" guarantee.
 *
 * @internal
 */
class FailingFilesystem extends Filesystem
{
    public function deleteFile(string $path)
    {
        return false;
    }
}
