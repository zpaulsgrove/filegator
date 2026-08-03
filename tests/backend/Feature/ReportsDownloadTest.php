<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Feature;

use Filegator\Services\Audit\ReportStore;
use Tests\Fakes\RecordingLogger;
use Tests\TestCase;

/**
 * @internal
 */
class ReportsDownloadTest extends TestCase
{
    const CSV = "\xEF\xBB\xBFtimestamp_unix,timestamp_iso,timestamp_local,user,role,action,path,folder,detail\r\n"
        ."1751328000,2025-07-01T00:00:00Z,2025-07-01 00:00:00+00:00,alice,user,delete,/clientA/secret.pdf,/clientA,\r\n";

    /**
     * Written with a directly-constructed store rather than through the booted
     * app, so seeding does not depend on request/boot ordering.
     */
    protected function seedReport(string $period = '2026-07'): string
    {
        $store = new ReportStore(new RecordingLogger());
        $store->init([
            'dir' => TEST_TMP_PATH.'reports',
            'key_path' => TEST_TMP_PATH.'reports_encryption.key',
            'max_age_days' => 100,
            'max_count' => 24,
        ]);

        return $store->write($period, self::CSV, [
            'events' => 1,
            'coverage' => 'complete',
            'filename' => 'filegator-activity-CONFIDENTIAL-2026-07-01-to-2026-07-31.csv',
        ]);
    }

    protected function streamedBody(): string
    {
        // Two nested buffers: the callback's own ob_flush() moves data from the
        // inner buffer into the outer one we read from.
        ob_start();
        ob_start();
        $this->streamedResponse->sendContent();
        ob_end_flush();

        return ob_get_clean();
    }

    public function testGuestCannotReachEitherRoute()
    {
        $this->signOut();

        $this->sendRequest('GET', '/admin/reports');
        $this->assertStatus(404);

        $this->sendRequest('POST', '/admin/reports/download', ['period' => '2026-07']);
        $this->assertStatus(404);
    }

    /**
     * Role gating, not merely authentication — and with the same 404 a guest
     * gets, since the route is never registered for them.
     */
    public function testSignedInNonAdminCannotReachEitherRoute()
    {
        $this->signIn('john@example.com', 'john123');

        $this->sendRequest('GET', '/admin/reports');
        $this->assertStatus(404);

        $this->sendRequest('POST', '/admin/reports/download', ['period' => '2026-07']);
        $this->assertStatus(404);
    }

    /**
     * A GET route would be CSRF-exempt (Security::init skips GET) and rideable
     * by a top-level cross-site navigation under SameSite=Lax cookies, so the
     * download must not be reachable that way.
     *
     * 405, not 404: the router matches the path and rejects the method. That
     * only happens for someone who already holds the admin role — a non-admin
     * never gets the route registered at all and still sees a 404, which is
     * what the role-gating test above covers.
     */
    public function testDownloadIsNotReachableByGet()
    {
        $this->signIn('admin@example.com', 'admin123');
        $this->seedReport();

        $this->sendRequest('GET', '/admin/reports/download', ['period' => '2026-07']);
        $this->assertStatus(405);
    }

    public function testAdminSeesMetadataButNoEventData()
    {
        $this->signIn('admin@example.com', 'admin123');
        $this->seedReport();

        $this->sendRequest('GET', '/admin/reports');
        $this->assertOk();

        $body = (string) $this->response->getContent();

        $this->assertStringContainsString('2026-07', $body);
        $this->assertStringContainsString('complete', $body);
        $this->assertStringNotContainsString('alice', $body);
        $this->assertStringNotContainsString('secret.pdf', $body);
        $this->assertStringNotContainsString('clientA', $body);
    }

    public function testAdminCanDownloadTheDecryptedCsv()
    {
        $this->signIn('admin@example.com', 'admin123');
        $this->seedReport();

        $this->sendRequest('POST', '/admin/reports/download', ['period' => '2026-07']);

        $this->assertSame(self::CSV, $this->streamedBody());
    }

    public function testDownloadSetsAttachmentAndHardeningHeaders()
    {
        $this->signIn('admin@example.com', 'admin123');
        $this->seedReport();

        $this->sendRequest('POST', '/admin/reports/download', ['period' => '2026-07']);
        $headers = $this->streamedResponse->headers;

        $this->assertStringContainsString('attachment', (string) $headers->get('content-disposition'));
        $this->assertStringContainsString('CONFIDENTIAL', (string) $headers->get('content-disposition'));
        $this->assertStringContainsString('text/csv', (string) $headers->get('content-type'));
        // Set explicitly on the streamed response: Security's headers go on the
        // Response that App sends after the controller already streamed, by
        // which point headers_sent() is true and Symfony drops them.
        $this->assertSame('nosniff', $headers->get('x-content-type-options'));
        // Symfony appends "private" of its own accord; no-store is the part
        // that keeps a CONFIDENTIAL CSV out of intermediary caches.
        $this->assertStringContainsString('no-store', (string) $headers->get('cache-control'));
    }

    /**
     * The route takes a period, never a path, so there is nothing to traverse
     * with. Every rejection must look identical so it cannot probe which
     * periods exist.
     */
    public function testTraversalAndUnknownPeriodsAreIndistinguishable()
    {
        $this->signIn('admin@example.com', 'admin123');
        $this->seedReport();

        $bodies = [];
        foreach (['../../private/users.json', '2026-99', '../2026-07', '', '2026-01'] as $period) {
            $this->sendRequest('POST', '/admin/reports/download', ['period' => $period]);
            $this->assertStatus(404);
            $bodies[] = (string) $this->response->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'all rejections must be byte-identical');
        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('users.json', $body);
        }
    }

    /**
     * A report the store cannot decrypt — a key created root-owned by a cron
     * run as the wrong user is the realistic case — must be a 500, never a 200
     * with an empty body, which would be a silently wrong compliance artifact.
     */
    public function testUndecryptableReportIsAnErrorNotAnEmptyCsv()
    {
        $this->signIn('admin@example.com', 'admin123');
        $this->seedReport();

        file_put_contents(
            TEST_TMP_PATH.'reports_encryption.key',
            random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        );

        $this->sendRequest('POST', '/admin/reports/download', ['period' => '2026-07']);

        $this->assertStatus(500);
        $this->assertStringNotContainsString('timestamp_unix', (string) $this->response->getContent());
    }

    /**
     * Unlike a batch archive, a report is a durable artifact under an explicit
     * retention policy — downloading must not consume it.
     */
    public function testReportSurvivesDownload()
    {
        $this->signIn('admin@example.com', 'admin123');
        $this->seedReport();

        $this->sendRequest('POST', '/admin/reports/download', ['period' => '2026-07']);
        $this->assertSame(self::CSV, $this->streamedBody());

        $this->sendRequest('POST', '/admin/reports/download', ['period' => '2026-07']);
        $this->assertSame(self::CSV, $this->streamedBody());
    }
}
