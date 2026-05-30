<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Fakes;

use Filegator\Services\Mailer\MailerInterface;
use Filegator\Services\Service;

class InMemoryMailer implements Service, MailerInterface
{
    public static $messages = [];

    public static $configured = true;

    /**
     * Artificial delay applied on every send() before recording the message.
     * Defaults to 0 (instant), matching the no-delay production-fake behaviour.
     * Tests opt in by setting this; TestCase::setUp() resets it to 0 so the
     * delay never leaks between tests.
     */
    public static int $delayMicroseconds = 0;

    /**
     * When true, the next send() reports failure (returns false) without
     * recording a message — simulating an SMTP/transport error so callers'
     * failure-handling branches can be exercised. Auto-clears after one send
     * and is reset to false in reset().
     */
    public static bool $failNextSend = false;

    public function init(array $config = [])
    {
        if (array_key_exists('configured', $config)) {
            self::$configured = (bool) $config['configured'];
        }
    }

    public function isConfigured(): bool
    {
        return self::$configured;
    }

    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null, ?string $fromEmail = null, ?string $fromName = null): bool
    {
        if (self::$failNextSend) {
            self::$failNextSend = false;
            return false;
        }
        if (self::$delayMicroseconds > 0) {
            usleep(self::$delayMicroseconds);
        }
        self::$messages[] = [
            'to' => $to,
            'subject' => $subject,
            'text' => $textBody,
            'html' => $htmlBody,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];
        return true;
    }

    public static function reset(): void
    {
        self::$messages = [];
        self::$configured = true;
        self::$delayMicroseconds = 0;
        self::$failNextSend = false;
    }

    public static function last(): ?array
    {
        return self::$messages ? end(self::$messages) : null;
    }
}
