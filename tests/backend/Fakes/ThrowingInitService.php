<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Fakes;

use Filegator\Services\Service;

/**
 * A service whose init() throws, to exercise App's bootstrap error handling
 * (the try/catch around the service-init loop). Registered in place of an early
 * service (the Logger) so the throw fires before request dispatch.
 */
class ThrowingInitService implements Service
{
    public function init(array $config = [])
    {
        throw new \RuntimeException('boom during service init');
    }
}
