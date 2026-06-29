<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Fakes;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Config;

/**
 * A Local adapter whose writeStream reports failure by returning false, with no
 * bytes committed — modelling a write that dies partway (full disk, a dropped
 * SFTP connection, a permission flip mid-stream). Lets a test prove that an
 * overwrite-in-place store() leaves the ORIGINAL file intact when the new write
 * fails, instead of the historic delete-then-write that destroyed it first.
 *
 * @internal
 */
class FailingWriteLocal extends Local
{
    public function writeStream($path, $resource, Config $config)
    {
        return false;
    }
}
