<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Audit;

/**
 * Raised when the monthly-report state file exists but cannot be parsed.
 *
 * Treated as a hard stop rather than "no state yet". Starting from an empty map
 * would make every backfill period look never-generated, so the job would
 * rebuild them all with fresh ids and orphan any download link an admin already
 * holds. Refusing keeps the damage at "no report this tick", which an operator
 * can see and fix.
 */
class CorruptStateException extends \RuntimeException
{
    public function __construct(string $path)
    {
        parent::__construct(
            'Monthly report state file is corrupt: '.$path
            .' — inspect it, then repair or remove it. Removing it makes the job treat every'
            .' period as never generated, which regenerates reports under new ids.'
        );
    }
}
