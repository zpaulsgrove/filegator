<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Logger\Adapters\MonoLogger;
use Filegator\Services\Mailer\Adapters\SymfonyMailer;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * End-to-end checks of what the SymfonyMailer adapter actually sends, through
 * the real Symfony Mailer, Mime, HttpClient and Postmark components. The E2E
 * suite swaps in a file-writing fake mailer, so without these tests a
 * regression in message construction (for example from a symfony/mime or
 * symfony/http-client update) would first show up in production email.
 *
 * @internal
 */
class SymfonyMailerMessageTest extends TestCase
{
    /** @var array<int, array{method: string, url: string, options: array}> */
    private $requests = [];

    /** @var SentMessage[] */
    private $captured = [];

    private function logger(): MonoLogger
    {
        $logger = new MonoLogger();
        $logger->init(['monolog_handlers' => [function () { return new NullHandler(); }]]);
        return $logger;
    }

    /**
     * Adapter whose transport is built by $factory instead of Transport::fromDsn().
     */
    private function adapter(array $config, callable $factory): SymfonyMailer
    {
        $mailer = new class($this->logger(), $factory) extends SymfonyMailer {
            private $factory;

            public function __construct($logger, callable $factory)
            {
                parent::__construct($logger);
                $this->factory = $factory;
            }

            protected function createTransport(string $dsn): TransportInterface
            {
                return ($this->factory)($dsn);
            }
        };
        $mailer->init($config);
        return $mailer;
    }

    /** Adapter that renders and keeps every message instead of delivering it. */
    private function capturingAdapter(): SymfonyMailer
    {
        $captured = &$this->captured;
        return $this->adapter(
            ['dsn' => 'smtp://smtp.example.test:587', 'from_email' => 'no-reply@example.test', 'from_name' => 'File Portal'],
            function () use (&$captured) {
                return new class($captured) extends AbstractTransport {
                    private $captured;

                    public function __construct(array &$captured)
                    {
                        parent::__construct();
                        $this->captured = &$captured;
                    }

                    protected function doSend(SentMessage $message): void
                    {
                        $this->captured[] = $message;
                    }

                    public function __toString(): string
                    {
                        return 'capture://';
                    }
                };
            }
        );
    }

    private function postmarkAdapter(int $status, array $responseBody): SymfonyMailer
    {
        $requests = &$this->requests;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $status, $responseBody) {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse(json_encode($responseBody), ['http_code' => $status]);
        });

        return $this->adapter(
            ['dsn' => 'postmark+api://server-token-123@default', 'from_email' => 'no-reply@example.test', 'from_name' => 'File Portal'],
            function (string $dsn) use ($client) {
                // Same DSN the adapter would use in production, including the
                // timeout it appends; only the HTTP client is swapped for a mock.
                return Transport::fromDsn($dsn, null, $client);
            }
        );
    }

    private function headerValue(array $options, string $name): ?string
    {
        foreach ($options['headers'] ?? [] as $line) {
            if (stripos($line, $name.':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }
        return null;
    }

    public function testPostmarkApiRequestCarriesTheMessage()
    {
        $mailer = $this->postmarkAdapter(200, ['MessageID' => 'mid-1', 'ErrorCode' => 0, 'Message' => 'OK', 'To' => 'user@example.test']);

        $ok = $mailer->send('user@example.test', 'Reset your password', "Use this link:\nhttps://portal.example.test/reset", '<p>Use <a href="https://portal.example.test/reset">this link</a></p>');

        $this->assertTrue($ok);
        $this->assertCount(1, $this->requests);
        $req = $this->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://api.postmarkapp.com/email', $req['url']);
        $this->assertSame('server-token-123', $this->headerValue($req['options'], 'X-Postmark-Server-Token'));

        $payload = json_decode($req['options']['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame('"File Portal" <no-reply@example.test>', $payload['From']);
        $this->assertSame('user@example.test', $payload['To']);
        $this->assertSame('Reset your password', $payload['Subject']);
        $this->assertSame("Use this link:\nhttps://portal.example.test/reset", $payload['TextBody']);
        $this->assertStringContainsString('href="https://portal.example.test/reset"', $payload['HtmlBody']);
    }

    public function testPostmarkApiErrorIsReportedAsFailure()
    {
        $mailer = $this->postmarkAdapter(422, ['ErrorCode' => 300, 'Message' => 'Invalid email request']);

        $this->assertFalse($mailer->send('user@example.test', 'Subject', 'Body'));
        $this->assertCount(1, $this->requests);
    }

    public function testRenderedMimeMessageIsWellFormed()
    {
        $mailer = $this->capturingAdapter();

        $ok = $mailer->send('user@example.test', 'Résumé of your account — done', "Plain body ünïcode\n", '<p>HTML body ünïcode</p>');

        $this->assertTrue($ok);
        $this->assertCount(1, $this->captured);
        $raw = $this->captured[0]->toString();
        [$head, $body] = explode("\r\n\r\n", $raw, 2);

        $this->assertMatchesRegularExpression('/^From: "?File Portal"? <no-reply@example\.test>\r?$/m', $head);
        $this->assertMatchesRegularExpression('/^To: user@example\.test\r?$/m', $head);
        $this->assertMatchesRegularExpression('/^MIME-Version: 1\.0\r?$/m', $head);
        $this->assertMatchesRegularExpression('/^Message-ID: <[^>]+@[^>]+>\r?$/m', $head);
        $this->assertMatchesRegularExpression('/^Date: /m', $head);
        // Non-ASCII subject must be RFC 2047 encoded, and decode back intact.
        $this->assertMatchesRegularExpression('/^Subject: =\?utf-8\?/mi', $head);
        $this->assertStringNotContainsString('Résumé', $head);
        $this->assertMatchesRegularExpression('/^Content-Type: multipart\/alternative; boundary=/m', $head);
        $subject = $this->captured[0]->getOriginalMessage()->getSubject();
        $this->assertSame('Résumé of your account — done', $subject);

        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $body);
        $this->assertStringContainsString('Content-Type: text/html; charset=utf-8', $body);
        $this->assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $body);
        $this->assertStringContainsString('Plain body =C3=BCn=C3=AFcode', $body);

        $envelope = $this->captured[0]->getEnvelope();
        $this->assertSame('no-reply@example.test', $envelope->getSender()->getAddress());
        $this->assertSame(['user@example.test'], array_map(function ($a) { return $a->getAddress(); }, $envelope->getRecipients()));
    }

    /**
     * Subjects and sender names can carry user-controlled text. CR/LF in them
     * must never start a new header line (header injection, e.g. a hidden Bcc).
     */
    public function testCrlfInSubjectOrSenderNameCannotInjectHeaders()
    {
        $mailer = $this->capturingAdapter();

        $mailer->send('user@example.test', "Hello\r\nBcc: attacker@evil.test", 'Body', null, null, "Portal\r\nBcc: attacker2@evil.test");

        if ($this->captured === []) {
            // Rejecting the message outright is also a safe outcome.
            $this->addToAssertionCount(1);
            return;
        }
        $raw = $this->captured[0]->toString();
        [$head] = explode("\r\n\r\n", $raw, 2);
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $head);
        foreach ($this->captured[0]->getEnvelope()->getRecipients() as $r) {
            $this->assertSame('user@example.test', $r->getAddress());
        }
    }
}
