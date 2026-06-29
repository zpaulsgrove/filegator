<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Fakes;

use Filegator\Services\Logger\LoggerInterface;

/**
 * In-memory logger that records messages so tests can assert on swallowed /
 * informational log lines without a real Monolog handler.
 *
 * @internal
 */
class RecordingLogger implements LoggerInterface
{
    /** @var string[] */
    public $messages = [];

    public function log(string $message, int $level = self::INFO)
    {
        $this->messages[] = $message;
    }
}
