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

/**
 * Test-only mailer that writes the LAST sent email to a JSON file on disk, so
 * an out-of-process E2E runner (Cypress) can read it and extract, e.g., a
 * password-reset token. The in-process InMemoryMailer keeps messages in a
 * static array, which a separate process can't see — hence this file variant.
 *
 * Bound only by the E2E seam config (configuration.e2e.php); never used by a
 * real deployment. Overwrites the file on each send (always-latest semantics).
 */
class FileMailer implements Service, MailerInterface
{
    protected $file;

    public function init(array $config = [])
    {
        $this->file = $config['file'] ?? sys_get_temp_dir().'/filegator_e2e_last_email.json';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null, ?string $fromEmail = null, ?string $fromName = null): bool
    {
        @mkdir(dirname($this->file), 0777, true);
        @file_put_contents($this->file, json_encode([
            'to' => $to,
            'subject' => $subject,
            'text' => $textBody,
            'html' => $htmlBody,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ]));

        return true;
    }
}
