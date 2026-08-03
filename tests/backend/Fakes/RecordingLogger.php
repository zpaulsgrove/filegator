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

    /**
     * Levels, parallel-indexed with $messages.
     *
     * The level is not incidental: production pins the Monolog handler at
     * WARNING (configuration_sample.php), so anything logged at the INFO
     * default is DISCARDED on a real deployment. A scheduled job whose only
     * evidence of having run is an INFO line leaves no trace at all. Recording
     * the level is what lets a test assert that a message operators must see
     * is emitted loudly enough to survive.
     *
     * @var int[]
     */
    public $levels = [];

    public function log(string $message, int $level = self::INFO)
    {
        $this->messages[] = $message;
        $this->levels[] = $level;
    }

    /**
     * Messages emitted at or above $level — i.e. what an operator would
     * actually see in production.
     *
     * @return string[]
     */
    public function messagesAtLeast(int $level): array
    {
        $out = [];
        foreach ($this->messages as $i => $message) {
            if (($this->levels[$i] ?? self::INFO) >= $level) {
                $out[] = $message;
            }
        }

        return $out;
    }
}
