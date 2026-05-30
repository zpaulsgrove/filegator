<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Mailer\Templates\PasswordResetTemplate;
use Tests\TestCase;

/**
 * @internal
 */
class PasswordResetTemplateTest extends TestCase
{
    public function testHtmlBodyEscapesHostileUsernameAndUrl()
    {
        $rendered = PasswordResetTemplate::render(
            'https://files.example.com/#/reset?token=abc"><script>evil()</script>',
            '<script>alert(1)</script>',
            60,
            'FileGator',
            []
        );

        // The raw hostile markup must NOT appear in the HTML body...
        $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered['html']);
        // ...only its escaped form.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered['html']);

        // The URL's injected quote + tags must be escaped too, so it cannot
        // break out of the href attribute.
        $this->assertStringNotContainsString('"><script>evil()</script>', $rendered['html']);
        $this->assertStringContainsString('&lt;script&gt;evil()', $rendered['html']);
    }

    public function testDefaultBrandingBranchUsesNeutralDefaultsAndOmitsOptionalBlocks()
    {
        $rendered = PasswordResetTemplate::render(
            'https://files.example.com/#/reset?token=abc123',
            'john@example.com',
            45,
            'FileGator',
            [] // no branding
        );

        // Default teal accent colour.
        $this->assertStringContainsString('#2c7a7b', $rendered['html']);
        // No logo block and no support footer when those branding values are
        // absent (the two conditional blocks collapse to empty strings).
        $this->assertStringNotContainsString('<img', $rendered['html']);
        $this->assertStringNotContainsString('mailto:', $rendered['html']);

        // TTL + URL render into both the HTML and the plain-text fallback.
        $this->assertStringContainsString('45 minutes', $rendered['html']);
        $this->assertStringContainsString('45 minutes', $rendered['text']);
        $this->assertStringContainsString('token=abc123', $rendered['text']);
    }
}
