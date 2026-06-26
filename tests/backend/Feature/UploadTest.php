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
