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

/**
 * A Local adapter whose writeStream succeeds but whose rename reports failure
 * by returning false — modelling the overwrite-in-place case where the new
 * bytes land safely in the temp sibling but the final move into place fails
 * (a permission flip on the target dir, an adapter error). Lets a test prove
 * that store() surfaces the failure (returns false) instead of reporting a
 * phantom success while the destination is left missing.
 *
 * @internal
 */
class FailingRenameLocal extends Local
{
    public function rename($path, $newpath)
    {
        return false;
    }
}
