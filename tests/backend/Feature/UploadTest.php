<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Feature;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\TestCase;

/**
 * @internal
 */
class UploadTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetTempDir();

        parent::setUp();
    }

    public function testSimpleFileUpload()
    {
        $this->signIn('john@example.com', 'john123');

        // create 5Kb dummy file
        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, 0.5 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'sample.txt', 'text/plain', null, true)];

        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 0.5 * 1024 * 1024,
            'resumableTotalChunks' => 1,
            'resumableTotalSize' => 0.5 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-SIMPLE-TEST',
            'resumableFilename' => 'sample.txt',
            'resumableRelativePath' => '/',
        ];

        $this->sendRequest('GET', '/upload', $data, $files);

        $this->assertStatus(204);

        $this->sendRequest('POST', '/upload', $data, $files);

        $this->assertOk();

        $this->sendRequest('POST', '/getdir', [
            'dir' => '/',
        ]);

        $this->assertOk();

        $this->assertResponseJsonHas([
            'data' => [
                'files' => [
                    0 => [
                        'type' => 'file',
                        'path' => '/sample.txt',
                        'name' => 'sample.txt',
                    ],
                ],
            ],
        ]);
    }

    public function testGuestWithUploadPermissionCanUpload()
    {
        // Swap in an auth adapter whose guest holds 'upload', so the router
        // registers /upload for an anonymous request and the controller runs.
        // Regression for the guest-upload NPE: with a real guest,
        // $this->auth->user() is null and the pre-fix code fataled on
        // ->getUsername() in both chunkCheck and upload.
        $this->overrideConfig([
            'services' => [
                'Filegator\Services\Auth\AuthInterface' => [
                    'handler' => '\Tests\Fakes\GuestUploadAuth',
                ],
            ],
        ]);

        // create 0.5Mb dummy file (fits in one chunk)
        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, 0.5 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'guest.txt', 'text/plain', null, true)];

        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 0.5 * 1024 * 1024,
            'resumableTotalChunks' => 1,
            'resumableTotalSize' => 0.5 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-GUEST-TEST',
            'resumableFilename' => 'guest.txt',
            'resumableRelativePath' => '/',
        ];

        // No signIn(): this is an anonymous guest. chunkCheck (GET) would fatal
        // pre-fix; post-fix it returns 204 and seeds the per-session token.
        $this->sendRequest('GET', '/upload', $data, $files);
        $this->assertStatus(204);

        // Carry the guest session forward so the per-session upload token stays
        // stable across the chunkCheck and upload requests.
        $this->captureSession();

        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertOk();

        $this->captureSession();

        $this->sendRequest('POST', '/getdir', [
            'dir' => '/',
        ]);
        $this->assertOk();
        $this->assertResponseJsonHas([
            'data' => [
                'files' => [
                    0 => [
                        'type' => 'file',
                        'path' => '/guest.txt',
                        'name' => 'guest.txt',
                    ],
                ],
            ],
        ]);
    }

    public function testUploadRecordsAudit()
    {
        @unlink(TEST_TMP_PATH.'audit_log.jsonl');
        @unlink(TEST_TMP_PATH.'audit_log.jsonl.pruned');

        $this->signIn('john@example.com', 'john123');

        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, 0.5 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'audited.txt', 'text/plain', null, true)];
        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 0.5 * 1024 * 1024,
            'resumableTotalChunks' => 1,
            'resumableTotalSize' => 0.5 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-AUDIT-TEST',
            'resumableFilename' => 'audited.txt',
            'resumableRelativePath' => '/',
        ];

        $this->sendRequest('GET', '/upload', $data, $files);
        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertOk();

        $audit = new \Filegator\Services\Audit\AuditLog(
            new class() implements \Filegator\Services\Logger\LoggerInterface {
                public function log(string $message, int $level = self::INFO) {}
            }
        );
        $audit->init([
            'log_file' => TEST_TMP_PATH.'audit_log.jsonl',
            'key_path' => TEST_TMP_PATH.'audit_encryption.key',
            'max_age_days' => 30,
        ]);

        $events = $audit->query(['action' => 'upload']);
        $this->assertCount(1, $events);
        $this->assertSame('john@example.com', $events[0]['user']);
        // john's homedir is /john, so the upload to '/' lands root-relative there.
        $this->assertSame('/john/audited.txt', $events[0]['path']);
    }

    public function testOverwriteUploadRecordsOverwrittenDetail()
    {
        @unlink(TEST_TMP_PATH.'audit_log.jsonl');
        @unlink(TEST_TMP_PATH.'audit_log.jsonl.pruned');

        // With overwrite_on_upload, a colliding upload replaces in place (no
        // upcount) and the trail must flag it as destructive via detail.
        $this->overrideConfig(['overwrite_on_upload' => true]);

        $this->signIn('john@example.com', 'john123');
        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/dup.txt'); // pre-existing target

        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, 0.5 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'dup.txt', 'text/plain', null, true)];
        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 0.5 * 1024 * 1024,
            'resumableTotalChunks' => 1,
            'resumableTotalSize' => 0.5 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-OVERWRITE-TEST',
            'resumableFilename' => 'dup.txt',
            'resumableRelativePath' => '/',
        ];

        $this->sendRequest('GET', '/upload', $data, $files);
        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertOk();

        $audit = new \Filegator\Services\Audit\AuditLog(
            new class() implements \Filegator\Services\Logger\LoggerInterface {
                public function log(string $message, int $level = self::INFO) {}
            }
        );
        $audit->init([
            'log_file' => TEST_TMP_PATH.'audit_log.jsonl',
            'key_path' => TEST_TMP_PATH.'audit_encryption.key',
            'max_age_days' => 30,
        ]);

        $events = $audit->query(['action' => 'upload']);
        $this->assertCount(1, $events);
        // Overwrite -> actual path is the original name (not upcounted)...
        $this->assertSame('/john/dup.txt', $events[0]['path']);
        // ...and the destructive nature is recorded.
        $this->assertSame('overwritten', $events[0]['detail']);
    }

    public function testFileUploadWithTwoChunks()
    {
        $this->signIn('john@example.com', 'john123');

        // create 1MB dummy file part 1
        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, 1 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'sample.txt', 'text/plain', null, true)];

        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 1 * 1024 * 1024,
            'resumableTotalChunks' => 2,
            'resumableTotalSize' => 1.5 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-MULTIPART-TEST',
            'resumableFilename' => 'sample.txt',
            'resumableRelativePath' => '/',
        ];

        // part does not exists
        $this->sendRequest('GET', '/upload', $data, $files);
        $this->assertStatus(204);

        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertOk();

        // this part should already exists, no need to upload again
        $this->sendRequest('GET', '/upload', $data, $files);
        $this->assertOk();

        // create 512Kb dummy file part 2
        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, 0.5 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'sample.txt', 'text/plain', null, true)];

        $data = [
            'resumableChunkNumber' => 2,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 0.5 * 1024 * 1024,
            'resumableTotalChunks' => 2,
            'resumableTotalSize' => 1.5 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-MULTIPART-TEST',
            'resumableFilename' => 'sample.txt',
            'resumableRelativePath' => '/',
        ];

        // part does not exists
        $this->sendRequest('GET', '/upload', $data, $files);
        $this->assertStatus(204);

        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertOk();

        $this->sendRequest('POST', '/getdir', [
            'dir' => '/',
        ]);

        $this->assertResponseJsonHas([
            'data' => [
                'files' => [
                    0 => [
                        'type' => 'file',
                        'name' => 'sample.txt',
                        'path' => '/sample.txt',
                        'size' => 1572864,
                    ],
                ],
            ],
        ]);
    }

    public function testUploadInvalidFile()
    {
        $this->signIn('john@example.com', 'john123');

        // Create the upload source here rather than relying on a sibling test
        // having left it behind. Under a full run an earlier test happens to
        // create it, but when the suite is run as a subset (e.g. Infection's
        // --only-covering-test-cases) this test would otherwise error on a
        // missing tmp_name file.
        $fp = fopen(TEST_FILE, 'w');
        fwrite($fp, 'lorem ipsum');
        fclose($fp);

        $file = [
            'tmp_name' => TEST_FILE,
            'full_path' => 'something', // new in php 8.1
            'name' => 'something',
            'type' => 'application/octet-stream',
            'size' => 12345,
            'error' => 0,
        ];

        $files = ['file' => $file];
        $data = [];

        $this->sendRequest('GET', '/upload', $data, $files);

        $this->assertStatus(204);

        $this->sendRequest('POST', '/upload', $data, $files);

        $this->assertStatus(422);
    }

    public function testUploadFileBiggerThanAllowed()
    {
        $this->signIn('john@example.com', 'john123');

        // create 3MB dummy file
        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, 3 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'sample.txt', 'text/plain', null, true)];

        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 1 * 1024 * 1024,
            'resumableTotalChunks' => 1,
            'resumableTotalSize' => 1 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-FAILED-TEST',
            'resumableFilename' => 'sample.txt',
            'resumableRelativePath' => '/',
        ];

        $this->sendRequest('POST', '/upload', $data, $files);

        $this->assertStatus(422);
    }

    public function testUploadFileBiggerThanAllowedUsingChunks()
    {
        $this->signIn('john@example.com', 'john123');

        // create 1MB dummy file
        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, 1 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'sample.txt', 'text/plain', null, true)];

        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 1 * 1024 * 1024,
            'resumableTotalChunks' => 3,
            'resumableTotalSize' => 2 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-FAILED2-TEST',
            'resumableFilename' => 'sample.txt',
            'resumableRelativePath' => '/',
        ];

        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertOk();

        // create 512Kb dummy file
        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, .5 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'sample.txt', 'text/plain', null, true)];
        $data = [
            'resumableChunkNumber' => 2,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 0.5 * 1024 * 1024,
            'resumableTotalChunks' => 3,
            'resumableTotalSize' => 2 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-FAILED2-TEST',
            'resumableFilename' => 'sample.txt',
            'resumableRelativePath' => '/',
        ];

        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertOk();

        // create 1MB dummy file
        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, 1 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'sample.txt', 'text/plain', null, true)];
        $data = [
            'resumableChunkNumber' => 3,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 1 * 1024 * 1024,
            'resumableTotalChunks' => 3,
            'resumableTotalSize' => 2 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-FAILED2-TEST',
            'resumableFilename' => 'sample.txt',
            'resumableRelativePath' => '/',
        ];

        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertStatus(422);

        // create 1MB dummy file
        $fp = fopen(TEST_FILE, 'w');
        fseek($fp, 1 * 1024 * 1024 - 1, SEEK_CUR);
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'sample.txt', 'text/plain', null, true)];
        $data = [
            'resumableChunkNumber' => 3,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 1 * 1024 * 1024,
            'resumableTotalChunks' => 3,
            'resumableTotalSize' => 2 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-FAILED2-TEST',
            'resumableFilename' => 'sample.txt',
            'resumableRelativePath' => '/',
        ];

        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertStatus(422);
    }

    public function testChunkedUploadReassemblesBytesInOrder()
    {
        // Two chunks with distinct, identifiable content. The reassembled file
        // must equal part1 . part2 exactly — proving order and integrity, not
        // just final byte count (which the existing size-only test guarantees).
        $this->signIn('john@example.com', 'john123');

        $part1 = str_repeat('A', 100);
        $part2 = str_repeat('B', 50);
        $total = strlen($part1) + strlen($part2);

        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableTotalChunks' => 2,
            'resumableTotalSize' => $total,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-ORDER-TEST',
            'resumableFilename' => 'ordered.txt',
            'resumableRelativePath' => '/',
        ];

        // chunk 1
        $fp = fopen(TEST_FILE, 'w');
        fwrite($fp, $part1);
        fclose($fp);
        $files = ['file' => new UploadedFile(TEST_FILE, 'ordered.txt', 'text/plain', null, true)];
        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertOk();

        // chunk 2
        $fp = fopen(TEST_FILE, 'w');
        fwrite($fp, $part2);
        fclose($fp);
        $data['resumableChunkNumber'] = 2;
        $files = ['file' => new UploadedFile(TEST_FILE, 'ordered.txt', 'text/plain', null, true)];
        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertOk();

        $this->assertStringEqualsFile(TEST_REPOSITORY.'/john/ordered.txt', $part1.$part2);
    }

    public function testChunkedAssemblyIsIsolatedFromCollidingScratchFile()
    {
        // Regression for the cross-tenant chunked-upload assembly collision.
        // The final assembly must NOT use the bare, user-supplied filename as
        // its shared-tmpfs scratch key, and must truncate (not append) when it
        // starts writing. We simulate a buffer left behind under the old scheme
        // — a file named exactly like the upload sitting in the shared tmpfs
        // dir, as a second concurrent upload of the same name would create —
        // and assert the stored result is exactly OUR bytes, never the stale
        // buffer + ours.
        $this->signIn('john@example.com', 'john123');

        // Buffer a colliding/old-scheme assembly would have written to.
        file_put_contents(TEST_TMP_PATH.'collide.txt', 'STALE-LEFTOVER-FROM-ANOTHER-UPLOAD');

        $part1 = str_repeat('X', 80);
        $part2 = str_repeat('Y', 40);

        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableTotalChunks' => 2,
            'resumableTotalSize' => strlen($part1) + strlen($part2),
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-COLLISION-TEST',
            'resumableFilename' => 'collide.txt',
            'resumableRelativePath' => '/',
        ];

        $fp = fopen(TEST_FILE, 'w');
        fwrite($fp, $part1);
        fclose($fp);
        $this->sendRequest('POST', '/upload', $data, ['file' => new UploadedFile(TEST_FILE, 'collide.txt', 'text/plain', null, true)]);
        $this->assertOk();

        $data['resumableChunkNumber'] = 2;
        $fp = fopen(TEST_FILE, 'w');
        fwrite($fp, $part2);
        fclose($fp);
        $this->sendRequest('POST', '/upload', $data, ['file' => new UploadedFile(TEST_FILE, 'collide.txt', 'text/plain', null, true)]);
        $this->assertOk();

        // The stored file is exactly our two parts — uncontaminated by the
        // stale scratch buffer, which the old bare-filename assembly would have
        // appended onto.
        $this->assertStringEqualsFile(TEST_REPOSITORY.'/john/collide.txt', $part1.$part2);
    }

    public function testUploadCannotTraverseOutsideHomedir()
    {
        // John (homedir /john) tries to smuggle a file into Jane's homedir by
        // setting resumableRelativePath to a traversal sequence. The storage
        // layer must collapse the `..` and confine the write to John's prefix.
        $this->signIn('john@example.com', 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        mkdir(TEST_REPOSITORY.'/jane');

        $fp = fopen(TEST_FILE, 'w');
        fwrite($fp, 'pwned');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'evil.txt', 'text/plain', null, true)];

        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 5,
            'resumableTotalChunks' => 1,
            'resumableTotalSize' => 5,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-TRAVERSAL-TEST',
            'resumableFilename' => 'evil.txt',
            'resumableRelativePath' => '../../jane',
        ];

        $this->sendRequest('POST', '/upload', $data, $files);
        $this->assertOk();

        // Security invariant: the file never lands in Jane's homedir, and never
        // escapes the repository root.
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/jane/evil.txt');
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/evil.txt');

        // It is confined to John's own homedir root (the `..` collapsed to '/').
        $this->assertFileExists(TEST_REPOSITORY.'/john/evil.txt');

        // And Jane, listing her own homedir, sees nothing.
        $this->signIn('jane@example.com', 'jane123');
        $this->sendRequest('POST', '/getdir', ['dir' => '/']);
        $this->assertOk();
        $this->assertStringNotContainsString('evil.txt', $this->response->getContent());
    }

    public function testFileUploadWithBadName()
    {
        $this->signIn('john@example.com', 'john123');

        // Write a known-size chunk so the assembly condition (chunks_size >=
        // total_size) fires deterministically. Previously this test relied on
        // a prior test leaving TEST_FILE large — an order-dependency that broke
        // in isolation.
        $fp = fopen(TEST_FILE, 'w');
        fwrite($fp, 'lorem');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'sample.txt', 'text/plain', null, true)];

        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 5,
            'resumableTotalChunks' => 1,
            'resumableTotalSize' => 5,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-SIMPLE-TEST',
            'resumableFilename' => "../\\s\"u<:>pe////rm?*|an\\.t\txt../;",
            'resumableRelativePath' => '/',
        ];

        $this->sendRequest('POST', '/upload', $data, $files);

        $this->assertOk();

        $this->sendRequest('POST', '/getdir', [
            'dir' => '/',
        ]);

        $this->assertResponseJsonHas([
            'data' => [
                'files' => [
                    0 => [
                        'type' => 'file',
                        'path' => '/..--s-u---pe----rm---an-.t-xt..--',
                        'name' => '..--s-u---pe----rm---an-.t-xt..--',
                    ],
                ],
            ],
        ]);
    }

    public function testUserWithoutUploadPermissionCannotUpload()
    {
        // jane has only ['read','write'] — no 'upload'. The route guard must
        // reject before the controller runs (fails closed with 404).
        $this->signIn('jane@example.com', 'jane123');

        $fp = fopen(TEST_FILE, 'w');
        fwrite($fp, 'a');
        fclose($fp);

        $files = ['file' => new UploadedFile(TEST_FILE, 'sample.txt', 'text/plain', null, true)];

        $data = [
            'resumableChunkNumber' => 1,
            'resumableChunkSize' => 1048576,
            'resumableCurrentChunkSize' => 0.5 * 1024 * 1024,
            'resumableTotalChunks' => 1,
            'resumableTotalSize' => 0.5 * 1024 * 1024,
            'resumableType' => 'text/plain',
            'resumableIdentifier' => 'CHUNKS-NOPERM-TEST',
            'resumableFilename' => 'sample.txt',
            'resumableRelativePath' => '/',
        ];

        $this->sendRequest('POST', '/upload', $data, $files);

        $this->assertStatus(404);
    }
}
