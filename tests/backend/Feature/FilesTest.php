<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Feature;

use Exception;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Auth\User;
use Tests\TestCase;

/**
 * @internal
 */
class FilesTest extends TestCase
{
    protected $timestamp;

    protected function setUp(): void
    {
        // parent::setUp() resets the shared static MockUsers store (and the
        // mailer/lockfile state). Without it, a test file that mutates users
        // earlier in the run (e.g. AdminTest deleting/renaming john, which
        // sorts before FilesTest) leaks into here and makes signIn() fail
        // with a 404. resetTempDir() then (re)creates the on-disk repository.
        parent::setUp();

        $this->resetTempDir();

        $this->timestamp = time();
    }

    protected function tearDown(): void
    {
        $this->resetTempDir();
    }

    public function testGuestCannotListDirectories()
    {
        $this->signOut();

        $this->sendRequest('POST', '/changedir', [
            'to' => '/',
        ]);

        $this->assertStatus(404);

        $this->sendRequest('POST', '/getdir', [
            'to' => '/',
        ]);

        $this->assertStatus(404);
    }

    public function testUserCanChangeDir()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        mkdir(TEST_REPOSITORY.'/john/johnsub');
        touch(TEST_REPOSITORY.'/john/john.txt', $this->timestamp);

        $this->sendRequest('POST', '/changedir', [
            'to' => '/',
        ]);

        $this->assertOk();

        $this->assertResponseJsonHas([
            'data' => [
                'files' => [
                    0 => [
                        'type' => 'dir',
                        'path' => '/johnsub',
                        'name' => 'johnsub',
                    ],
                    1 => [
                        'type' => 'file',
                        'path' => '/john.txt',
                        'name' => 'john.txt',
                        'time' => $this->timestamp,
                    ],
                ],
            ],
        ]);
    }

    public function testUserCanListHisHomeDir()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        mkdir(TEST_REPOSITORY.'/john/johnsub');
        touch(TEST_REPOSITORY.'/john/john.txt', $this->timestamp);

        $this->sendRequest('POST', '/getdir', [
            'dir' => '/',
        ]);

        $this->assertOk();

        $this->assertResponseJsonHas([
            'data' => [
                'files' => [
                    0 => [
                        'type' => 'dir',
                        'path' => '/johnsub',
                        'name' => 'johnsub',
                    ],
                    1 => [
                        'type' => 'file',
                        'path' => '/john.txt',
                        'name' => 'john.txt',
                        'time' => $this->timestamp,
                    ],
                ],
            ],
        ]);
    }

    public function testDeleteItems()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        mkdir(TEST_REPOSITORY.'/john/johnsub');
        touch(TEST_REPOSITORY.'/john/john.txt', $this->timestamp);

        $items = [
            0 => [
                'type' => 'dir',
                'path' => '/johnsub',
                'name' => 'johnsub',
                'time' => $this->timestamp,
            ],
            1 => [
                'type' => 'file',
                'path' => '/john.txt',
                'name' => 'john.txt',
                'time' => $this->timestamp,
            ],
        ];

        $this->sendRequest('POST', '/deleteitems', [
            'items' => $items,
        ]);

        $this->assertOk();
    }

    /**
     * Clear any prior audit log so each audit test starts from empty, and
     * return the on-disk paths the running app writes to.
     */
    private function freshAuditLog(): array
    {
        $logFile = TEST_TMP_PATH.'audit_log.jsonl';
        $keyPath = TEST_TMP_PATH.'audit_encryption.key';
        @unlink($logFile);
        @unlink($logFile.'.pruned');

        return [$logFile, $keyPath];
    }

    /**
     * Read the encrypted audit log back through a fresh service instance using
     * the same key file the request created.
     */
    private function auditEvents(array $filters = []): array
    {
        [$logFile, $keyPath] = [TEST_TMP_PATH.'audit_log.jsonl', TEST_TMP_PATH.'audit_encryption.key'];
        $audit = new \Filegator\Services\Audit\AuditLog(
            new class() implements \Filegator\Services\Logger\LoggerInterface {
                public function log(string $message, int $level = self::INFO) {}
            }
        );
        $audit->init(['log_file' => $logFile, 'key_path' => $keyPath, 'max_age_days' => 30]);

        return $audit->query($filters);
    }

    public function testDeleteRecordsAuditWithRootRelativePath()
    {
        $this->freshAuditLog();
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/john.txt', $this->timestamp);

        $this->sendRequest('POST', '/deleteitems', [
            'items' => [
                ['type' => 'file', 'path' => '/john.txt', 'name' => 'john.txt', 'time' => $this->timestamp],
            ],
        ]);
        $this->assertOk();

        $events = $this->auditEvents();
        $this->assertCount(1, $events);
        $this->assertSame('delete', $events[0]['action']);
        $this->assertSame($username, $events[0]['user']);
        // John's homedir is /john, so his homedir-relative '/john.txt' is
        // recorded in root-relative space — not ambiguous across users.
        $this->assertSame('/john/john.txt', $events[0]['path']);
    }

    public function testAuditRecordsActualUpcountedPathOnCollision()
    {
        $this->freshAuditLog();
        $this->signIn('john@example.com', 'john123');
        mkdir(TEST_REPOSITORY.'/john');

        // Create the same name twice; the second collides and the storage
        // layer upcounts it to 'dup (1).txt'. The audit must record the ACTUAL
        // stored name, not the requested one.
        $this->sendRequest('POST', '/createnew', ['type' => 'file', 'name' => 'dup.txt']);
        $this->assertOk();
        $this->sendRequest('POST', '/createnew', ['type' => 'file', 'name' => 'dup.txt']);
        $this->assertOk();

        $paths = array_column($this->auditEvents(['action' => 'create']), 'path');
        $this->assertContains('/john/dup.txt', $paths);
        $this->assertContains('/john/dup (1).txt', $paths);
    }

    public function testDeleteMultipleItemsRecordsOneRowPerItem()
    {
        $this->freshAuditLog();
        $this->signIn('john@example.com', 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/one.txt', $this->timestamp);
        touch(TEST_REPOSITORY.'/john/two.txt', $this->timestamp);

        $this->sendRequest('POST', '/deleteitems', [
            'items' => [
                ['type' => 'file', 'path' => '/one.txt', 'name' => 'one.txt', 'time' => $this->timestamp],
                ['type' => 'file', 'path' => '/two.txt', 'name' => 'two.txt', 'time' => $this->timestamp],
            ],
        ]);
        $this->assertOk();

        $paths = array_column($this->auditEvents(['action' => 'delete']), 'path');
        $this->assertCount(2, $paths);
        $this->assertContains('/john/one.txt', $paths);
        $this->assertContains('/john/two.txt', $paths);
    }

    public function testCopyRecordsAuditWithActualDestAndFromDetail()
    {
        $this->freshAuditLog();
        $this->signIn('admin@example.com', 'admin123');

        touch(TEST_REPOSITORY.'/a.txt', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/dest');

        $this->sendRequest('POST', '/copyitems', [
            'items' => [['type' => 'file', 'path' => '/a.txt', 'name' => 'a.txt', 'time' => $this->timestamp]],
            'destination' => '/dest/',
        ]);
        $this->assertOk();

        $events = $this->auditEvents(['action' => 'copy']);
        $this->assertCount(1, $events);
        // admin's homedir is '/', so paths are the literal root-relative paths.
        $this->assertSame('/dest/a.txt', $events[0]['path']);
        $this->assertSame('from /a.txt', $events[0]['detail']);
    }

    public function testMoveRecordsAuditWithActualDestAndFromDetail()
    {
        $this->freshAuditLog();
        $this->signIn('admin@example.com', 'admin123');

        touch(TEST_REPOSITORY.'/m.txt', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/dest');

        $this->sendRequest('POST', '/moveitems', [
            'items' => [['type' => 'file', 'path' => '/m.txt', 'name' => 'm.txt', 'time' => $this->timestamp]],
            'destination' => '/dest/',
        ]);
        $this->assertOk();

        $events = $this->auditEvents(['action' => 'move']);
        $this->assertCount(1, $events);
        $this->assertSame('/dest/m.txt', $events[0]['path']);
        $this->assertSame('from /m.txt', $events[0]['detail']);
    }

    public function testRenameRecordsAudit()
    {
        $this->freshAuditLog();
        $this->signIn('admin@example.com', 'admin123');

        touch(TEST_REPOSITORY.'/old.txt', $this->timestamp);

        $this->sendRequest('POST', '/renameitem', [
            'destination' => '/',
            'from' => 'old.txt',
            'to' => 'new.txt',
        ]);
        $this->assertOk();

        $events = $this->auditEvents(['action' => 'rename']);
        $this->assertCount(1, $events);
        $this->assertSame('/new.txt', $events[0]['path']);
        $this->assertSame('from /old.txt', $events[0]['detail']);
    }

    public function testSaveContentRecordsActualPath()
    {
        $this->freshAuditLog();
        $this->signIn('admin@example.com', 'admin123');

        $this->sendRequest('POST', '/savecontent', [
            'name' => 'note.txt',
            'content' => 'hello',
        ]);
        $this->assertOk();

        $events = $this->auditEvents(['action' => 'save']);
        $this->assertCount(1, $events);
        $this->assertSame('/note.txt', $events[0]['path']);
        $this->assertFileExists(TEST_REPOSITORY.'/note.txt');
    }

    public function testAuditLogEndpointReturnsCrossUserEventsThroughHttp()
    {
        $this->freshAuditLog();

        // Two different users create the SAME relative filename.
        $this->signIn('john@example.com', 'john123');
        mkdir(TEST_REPOSITORY.'/john');
        $this->sendRequest('POST', '/createnew', ['type' => 'file', 'name' => 'shared.txt']);
        $this->assertOk();

        $this->signIn('jane@example.com', 'jane123');
        mkdir(TEST_REPOSITORY.'/jane');
        $this->sendRequest('POST', '/createnew', ['type' => 'file', 'name' => 'shared.txt']);
        $this->assertOk();

        // An admin reads the global trail through the REAL HTTP endpoint
        // (route gate + controller + {events:[...]} envelope), not a hand-built
        // service instance.
        $this->signIn('admin@example.com', 'admin123');
        $this->sendRequest('GET', '/admin/audit-log');
        $this->assertOk();

        $events = $this->decodeResponseJson()['data']['events'];
        // Exactly two events — array_column-by-path would mask an over-recording
        // regression, so pin the count and actions before mapping.
        $this->assertCount(2, $events);
        $this->assertSame(['create', 'create'], array_column($events, 'action'));

        $byPath = array_column($events, null, 'path');
        // Identical relative names, distinct global paths + correct attribution.
        $this->assertArrayHasKey('/john/shared.txt', $byPath);
        $this->assertArrayHasKey('/jane/shared.txt', $byPath);
        $this->assertSame('john@example.com', $byPath['/john/shared.txt']['user']);
        $this->assertSame('jane@example.com', $byPath['/jane/shared.txt']['user']);
    }

    public function testCreateFolderRecordsFolderDetail()
    {
        $this->freshAuditLog();
        $this->signIn('john@example.com', 'john123');
        mkdir(TEST_REPOSITORY.'/john');

        $this->sendRequest('POST', '/createnew', ['type' => 'dir', 'name' => 'newfolder']);
        $this->assertOk();

        $events = $this->auditEvents(['action' => 'create']);
        $this->assertCount(1, $events);
        $this->assertSame('/john/newfolder', $events[0]['path']);
        // The dir/file distinction in the trail lives only in this detail field.
        $this->assertSame('folder', $events[0]['detail']);
    }

    public function testNoAuditRowWhenStorageReturnsFalse()
    {
        // When the storage adapter reports failure (returns false rather than
        // throwing — e.g. SFTP/FTP), the controller must NOT record a phantom
        // event. Swap in a Filesystem whose deleteFile() returns false.
        $this->freshAuditLog();
        $this->overrideConfig(['services' => [
            'Filegator\Services\Storage\Filesystem' => ['handler' => '\Tests\Fakes\FailingFilesystem'],
        ]]);

        $this->signIn('john@example.com', 'john123');
        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/keep.txt');

        $this->sendRequest('POST', '/deleteitems', [
            'items' => [['type' => 'file', 'path' => '/keep.txt', 'name' => 'keep.txt']],
        ]);
        $this->assertOk();

        $this->assertSame([], $this->auditEvents(['action' => 'delete']), 'failed op records nothing');
    }

    public function testAuditLogEndpointForwardsActionFilter()
    {
        $this->freshAuditLog();
        $this->signIn('admin@example.com', 'admin123');

        touch(TEST_REPOSITORY.'/del.txt', $this->timestamp);
        $this->sendRequest('POST', '/createnew', ['type' => 'file', 'name' => 'made.txt']);
        $this->assertOk();
        $this->sendRequest('POST', '/deleteitems', [
            'items' => [['type' => 'file', 'path' => '/del.txt', 'name' => 'del.txt', 'time' => $this->timestamp]],
        ]);
        $this->assertOk();

        // Controller must forward request filters into AuditLog::query().
        $this->sendRequest('GET', '/admin/audit-log', ['action' => 'delete']);
        $this->assertOk();
        $events = $this->decodeResponseJson()['data']['events'];
        $this->assertCount(1, $events);
        $this->assertSame('delete', $events[0]['action']);
    }

    public function testZipAndUnzipRecordAudit()
    {
        $this->freshAuditLog();
        $this->signIn('admin@example.com', 'admin123');
        touch(TEST_REPOSITORY.'/z1.txt', $this->timestamp);

        $this->sendRequest('POST', '/zipitems', [
            'items' => [['type' => 'file', 'path' => '/z1.txt', 'name' => 'z1.txt', 'time' => $this->timestamp]],
            'destination' => '/',
            'name' => 'arch.zip',
        ]);
        $this->assertOk();

        $zip = $this->auditEvents(['action' => 'zip']);
        $this->assertCount(1, $zip);
        $this->assertSame('admin@example.com', $zip[0]['user']);
        $this->assertSame('/arch.zip', $zip[0]['path']);

        $this->sendRequest('POST', '/unzipitem', [
            'item' => '/arch.zip',
            'destination' => '/',
        ]);
        $this->assertOk();

        $unzip = $this->auditEvents(['action' => 'unzip']);
        $this->assertCount(1, $unzip);
        $this->assertSame('from /arch.zip', $unzip[0]['detail']);
    }

    public function testChmodRecordsAudit()
    {
        $this->freshAuditLog();

        // Provision a chmod-capable user without touching the shared fixture
        // (mirrors testChmodItemsChangesFilePermissions).
        $app = $this->sendRequest('GET', '/getuser');
        $auth = $app->resolve(AuthInterface::class);
        $cu = new User();
        $cu->setRole('user');
        $cu->setHomedir('/cu');
        $cu->setUsername('cu@example.com');
        $cu->setName('Chmod User');
        $cu->setPermissions(['read', 'write', 'chmod']);
        $auth->add($cu, 'cu12345');

        $this->signIn('cu@example.com', 'cu12345');
        mkdir(TEST_REPOSITORY.'/cu');
        touch(TEST_REPOSITORY.'/cu/perm.txt');

        $this->sendRequest('POST', '/chmoditems', [
            'items' => [['type' => 'file', 'path' => '/perm.txt', 'name' => 'perm.txt']],
            'permissions' => '0600',
        ]);
        $this->assertOk();

        $chmod = $this->auditEvents(['action' => 'chmod']);
        $this->assertCount(1, $chmod);
        $this->assertSame('/cu/perm.txt', $chmod[0]['path']);
        $this->assertSame('mode 0600', $chmod[0]['detail']);
    }

    public function testFileOpSucceedsWhenAuditLogIsUnwritable()
    {
        // A broken audit sink must never turn a successful file op into a 500
        // (the feature's headline safety property).
        $this->overrideConfig(['services' => [
            'Filegator\Services\Audit\AuditLog' => ['config' => [
                'log_file' => TEST_TMP_PATH.'no_such_dir/audit.jsonl', // parent missing -> writes fail
                'key_path' => TEST_TMP_PATH.'audit_encryption.key',
                'max_age_days' => 30,
            ]],
        ]]);

        $this->signIn('john@example.com', 'john123');
        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/del.txt', $this->timestamp);

        $this->sendRequest('POST', '/deleteitems', [
            'items' => [['type' => 'file', 'path' => '/del.txt', 'name' => 'del.txt', 'time' => $this->timestamp]],
        ]);

        $this->assertOk();
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/john/del.txt');
    }

    public function testDownloadFileHeaders()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/john.txt', $this->timestamp);
        file_put_contents(TEST_REPOSITORY.'/john/john.txt', '123456');
        touch(TEST_REPOSITORY.'/john/image.jpg', $this->timestamp);
        touch(TEST_REPOSITORY.'/john/vector.svg', $this->timestamp);
        touch(TEST_REPOSITORY.'/john/inlinedoc.pdf', $this->timestamp);

        $path_encoded = base64_encode('john.txt');
        $this->sendRequest('GET', '/download&path='.$path_encoded);
        $headers = $this->streamedResponse->headers;
        $this->assertEquals("attachment; filename=file; filename*=utf-8''john.txt", $headers->get('content-disposition'));
        $this->assertEquals('text/plain', $headers->get('content-type'));
        $this->assertEquals('binary', $headers->get('content-transfer-encoding'));
        $this->assertEquals(6, $headers->get('content-length'));
        $this->assertOk();

        $path_encoded = base64_encode('image.jpg');
        $this->sendRequest('GET', '/download&path='.$path_encoded);
        $headers = $this->streamedResponse->headers;
        $this->assertEquals("attachment; filename=file; filename*=utf-8''image.jpg", $headers->get('content-disposition'));
        $this->assertEquals('image/jpeg', $headers->get('content-type'));
        $this->assertEquals('binary', $headers->get('content-transfer-encoding'));
        $this->assertEquals(0, $headers->get('content-length'));
        $this->assertOk();

        $path_encoded = base64_encode('vector.svg');
        $this->sendRequest('GET', '/download&path='.$path_encoded);
        $headers = $this->streamedResponse->headers;
        $this->assertEquals("attachment; filename=file; filename*=utf-8''vector.svg", $headers->get('content-disposition'));
        $this->assertEquals('image/svg+xml', $headers->get('content-type'));
        $this->assertEquals('binary', $headers->get('content-transfer-encoding'));
        $this->assertOk();

        $path_encoded = base64_encode('inlinedoc.pdf');
        $this->sendRequest('GET', '/download&path='.$path_encoded);
        $headers = $this->streamedResponse->headers;
        $this->assertEquals("inline; filename=file; filename*=utf-8''inlinedoc.pdf", $headers->get('content-disposition'));
        $this->assertEquals('application/pdf', $headers->get('content-type'));
        $this->assertEquals('binary', $headers->get('content-transfer-encoding'));
        $this->assertOk();
    }

    public function testDownloadPDFFileHeaders()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/john.pdf', $this->timestamp);

        $path_encoded = base64_encode('john.pdf');
        $this->sendRequest('GET', '/download&path='.$path_encoded);

        $headers = $this->streamedResponse->headers;
        $this->assertEquals("inline; filename=file; filename*=utf-8''john.pdf", $headers->get('content-disposition'));
        $this->assertEquals('application/pdf', $headers->get('content-type'));
        $this->assertEquals('binary', $headers->get('content-transfer-encoding'));
        $this->assertEquals(0, $headers->get('content-length'));

        $this->assertOk();
    }

    public function testDownloadUTF8File()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/ąčęėįšųū.txt', $this->timestamp);

        $path_encoded = base64_encode('/ąčęėįšųū.txt');
        $this->sendRequest('GET', '/download&path='.$path_encoded);

        $this->assertOk();
    }

    public function testGuestCannotDownloadFilesWithoutDownloadPermissions()
    {
        touch(TEST_REPOSITORY.'/test.txt', $this->timestamp);

        $path_encoded = base64_encode('test.txt');
        $this->sendRequest('GET', '/download&path='.$path_encoded);

        $this->assertStatus(404);
    }

    public function testDownloadFileOnlyWithPermissions()
    {
        // jane does not have download permissions
        $username = 'jane@example.com';
        $this->signIn($username, 'jane123');

        mkdir(TEST_REPOSITORY.'/jane');
        touch(TEST_REPOSITORY.'/jane/jane.txt', $this->timestamp);

        $path_encoded = base64_encode('jane.txt');
        $this->sendRequest('GET', '/download&path='.$path_encoded);

        $this->assertStatus(404);
    }

    public function testDownloadMissingFileThrowsRedirect()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        $path_encoded = base64_encode('missing.txt');
        $this->sendRequest('GET', '/download&path='.$path_encoded);

        $this->assertStatus(302);
    }

    public function testRenameJohnsFile()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/john.txt', $this->timestamp);

        $this->sendRequest('POST', '/renameitem', [
            'from' => '/john.txt',
            'to' => '/john2.txt',
        ]);
        $this->assertOk();

        $this->assertFileExists(TEST_REPOSITORY.'/john/john2.txt');
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/john/john.txt');
    }

    public function testRenameMissingfileThrowsException()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        $this->expectException(Exception::class);

        $this->sendRequest('POST', '/renameitem', [
            'from' => 'missing.txt',
            'to' => 'john2.txt',
        ]);
    }

    public function testDeleteMissingItemsThrowsException()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        $items = [
            0 => [
                'type' => 'file',
                'path' => '/missing',
                'name' => 'missing',
                'time' => $this->timestamp,
            ],
        ];

        $this->expectException(Exception::class);

        $this->sendRequest('POST', '/deleteitems', [
            'items' => $items,
        ]);
    }

    public function testCreateNewDirAndFileInside()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');

        $this->sendRequest('POST', '/createnew', [
            'type' => 'dir',
            'name' => 'maximus',
        ]);
        $this->assertOk();

        $this->sendRequest('POST', '/changedir', [
            'to' => '/maximus/',
        ]);
        $this->assertOk();

        $this->sendRequest('POST', '/createnew', [
            'type' => 'file',
            'name' => 'samplefile.txt',
        ]);
        $this->assertOk();

        $this->assertDirectoryExists(TEST_REPOSITORY.'/john/maximus');
        $this->assertFileExists(TEST_REPOSITORY.'/john/maximus/samplefile.txt');
    }

    public function testCopyAdminFiles()
    {
        $username = 'admin@example.com';
        $this->signIn($username, 'admin123');

        touch(TEST_REPOSITORY.'/a.txt', $this->timestamp);
        touch(TEST_REPOSITORY.'/c.zip', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/sub');
        mkdir(TEST_REPOSITORY.'/sub/sub1');
        mkdir(TEST_REPOSITORY.'/john');
        mkdir(TEST_REPOSITORY.'/john/johnsub');

        $items = [
            0 => [
                'type' => 'file',
                'path' => '/a.txt',
                'name' => 'a.txt',
                'time' => $this->timestamp,
            ],
            1 => [
                'type' => 'file',
                'path' => '/c.zip',
                'name' => 'c.zip',
                'time' => $this->timestamp,
            ],
            2 => [
                'type' => 'dir',
                'path' => '/sub',
                'name' => 'sub',
                'time' => $this->timestamp,
            ],
        ];

        $this->sendRequest('POST', '/copyitems', [
            'items' => $items,
            'destination' => '/john/johnsub/',
        ]);

        $this->assertOk();

        $this->assertFileExists(TEST_REPOSITORY.'/john/johnsub/a.txt');
        $this->assertFileExists(TEST_REPOSITORY.'/john/johnsub/c.zip');
        $this->assertDirectoryExists(TEST_REPOSITORY.'/john/johnsub/sub/');
        $this->assertDirectoryExists(TEST_REPOSITORY.'/john/johnsub/sub/sub1');
    }

    public function testCopyInvalidFilesThrowsException()
    {
        $username = 'admin@example.com';
        $this->signIn($username, 'admin123');

        $items = [
            0 => [
                'type' => 'file',
                'path' => '/missin.txt',
                'name' => 'missina.txt',
                'time' => $this->timestamp,
            ],
        ];

        $this->expectException(Exception::class);

        $this->sendRequest('POST', '/copyitems', [
            'items' => $items,
            'destination' => '/john/johnsub/',
        ]);
    }

    public function testMoveFiles()
    {
        $username = 'admin@example.com';
        $this->signIn($username, 'admin123');

        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/a.txt', $this->timestamp);
        touch(TEST_REPOSITORY.'/b.txt', $this->timestamp);

        $items = [
            0 => [
                'type' => 'file',
                'path' => '/a.txt',
                'name' => 'a.txt',
                'time' => $this->timestamp,
            ],
            1 => [
                'type' => 'file',
                'path' => '/b.txt',
                'name' => 'b.txt',
                'time' => $this->timestamp,
            ],
        ];

        $this->sendRequest('POST', '/moveitems', [
            'items' => $items,
            'destination' => '/john',
        ]);

        $this->assertOk();

        $this->assertFileExists(TEST_REPOSITORY.'/john/a.txt');
        $this->assertFileExists(TEST_REPOSITORY.'/john/b.txt');
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/a.txt');
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/b.txt');
    }

    public function testMoveDirsWithContent()
    {
        $username = 'admin@example.com';
        $this->signIn($username, 'admin123');

        mkdir(TEST_REPOSITORY.'/sub');
        mkdir(TEST_REPOSITORY.'/sub/sub1');
        touch(TEST_REPOSITORY.'/sub/sub1/f.txt', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/jane');
        touch(TEST_REPOSITORY.'/jane/cookie.txt', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/john');

        $items = [
            0 => [
                'type' => 'dir',
                'path' => '/sub',
                'name' => 'sub',
                'time' => $this->timestamp,
            ],
            1 => [
                'type' => 'dir',
                'path' => '/jane',
                'name' => 'jane',
                'time' => $this->timestamp,
            ],
        ];

        $this->sendRequest('POST', '/moveitems', [
            'items' => $items,
            'destination' => '/john',
        ]);

        $this->assertOk();

        $this->assertDirectoryDoesNotExist(TEST_REPOSITORY.'/jane');
        $this->assertDirectoryDoesNotExist(TEST_REPOSITORY.'/sub');
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/sub/sub1/f.txt');

        $this->assertDirectoryExists(TEST_REPOSITORY.'/john/jane');
        $this->assertDirectoryExists(TEST_REPOSITORY.'/john/sub');
        $this->assertDirectoryExists(TEST_REPOSITORY.'/john/sub/sub1');
        $this->assertFileExists(TEST_REPOSITORY.'/john/sub/sub1/f.txt');
        $this->assertFileExists(TEST_REPOSITORY.'/john/jane/cookie.txt');
    }

    public function testZipFilesOnly()
    {
        $username = 'admin@example.com';
        $this->signIn($username, 'admin123');

        touch(TEST_REPOSITORY.'/a.txt', $this->timestamp);
        touch(TEST_REPOSITORY.'/b.txt', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/john');

        $items = [
            0 => [
                'type' => 'file',
                'path' => '/a.txt',
                'name' => 'a.txt',
                'time' => $this->timestamp,
            ],
            1 => [
                'type' => 'file',
                'path' => '/b.txt',
                'name' => 'b.txt',
                'time' => $this->timestamp,
            ],
        ];

        $this->sendRequest('POST', '/zipitems', [
            'name' => 'compressed.zip',
            'items' => $items,
            'destination' => '/john',
        ]);

        $this->assertOk();

        $this->assertFileExists(TEST_REPOSITORY.'/a.txt');
        $this->assertFileExists(TEST_REPOSITORY.'/b.txt');
        $this->assertFileExists(TEST_REPOSITORY.'/john/compressed.zip');
    }

    public function testZipFilesAndDirectories()
    {
        $username = 'admin@example.com';
        $this->signIn($username, 'admin123');

        touch(TEST_REPOSITORY.'/a.txt', $this->timestamp);
        touch(TEST_REPOSITORY.'/b.txt', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/sub');
        mkdir(TEST_REPOSITORY.'/jane');

        $items = [
            0 => [
                'type' => 'file',
                'path' => '/a.txt',
                'name' => 'a.txt',
                'time' => $this->timestamp,
            ],
            1 => [
                'type' => 'file',
                'path' => '/b.txt',
                'name' => 'b.txt',
                'time' => $this->timestamp,
            ],
            2 => [
                'type' => 'dir',
                'path' => '/sub',
                'name' => 'sub',
                'time' => $this->timestamp,
            ],
        ];

        $this->sendRequest('POST', '/zipitems', [
            'name' => 'compressed2.zip',
            'items' => $items,
            'destination' => '/jane',
        ]);

        $this->assertOk();

        $this->assertFileExists(TEST_REPOSITORY.'/a.txt');
        $this->assertFileExists(TEST_REPOSITORY.'/b.txt');
        $this->assertDirectoryExists(TEST_REPOSITORY.'/sub');
        $this->assertFileExists(TEST_REPOSITORY.'/jane/compressed2.zip');
    }

    public function testUnzipArchive()
    {
        $username = 'admin@example.com';
        $this->signIn($username, 'admin123');

        copy(TEST_ARCHIVE, TEST_REPOSITORY.'/c.zip');
        mkdir(TEST_REPOSITORY.'/jane');

        $this->sendRequest('POST', '/unzipitem', [
            'item' => '/c.zip',
            'destination' => '/jane',
        ]);

        $this->assertOk();

        $this->assertFileExists(TEST_REPOSITORY.'/jane/one.txt');
        $this->assertFileExists(TEST_REPOSITORY.'/jane/two.txt');
        $this->assertDirectoryExists(TEST_REPOSITORY.'/jane/onetwo');
        $this->assertFileExists(TEST_REPOSITORY.'/jane/onetwo/three.txt');

        // Content integrity: extracted bytes must match the archive entries,
        // not merely exist (a truncated/empty extraction would pass otherwise).
        $this->assertStringEqualsFile(TEST_REPOSITORY.'/jane/one.txt', "content1\n");
        $this->assertStringEqualsFile(TEST_REPOSITORY.'/jane/two.txt', "content2\n");
        $this->assertStringEqualsFile(TEST_REPOSITORY.'/jane/onetwo/three.txt', "content3\n");
    }

    public function testUnzipCannotEscapeHomedirViaZipSlip()
    {
        // A crafted archive whose entries use `../` traversal must NOT be able
        // to write outside the unzip destination / the caller's homedir.
        $this->signIn('john@example.com', 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        mkdir(TEST_REPOSITORY.'/jane');

        $zip_path = TEST_REPOSITORY.'/john/evil.zip';
        $zip = new \ZipArchive();
        $zip->open($zip_path, \ZipArchive::CREATE);
        $zip->addFromString('../jane/evil.txt', 'pwned');       // sibling homedir
        $zip->addFromString('../../escape.txt', 'pwned');       // repository root
        $zip->addFromString('safe.txt', 'ok');                  // legitimate entry
        $zip->close();

        // The uncompress may legitimately neutralize, skip, or throw on the
        // hostile entries; any of those is acceptable as long as the security
        // invariant below holds. A thrown exception is a safe outcome too.
        try {
            $this->sendRequest('POST', '/unzipitem', [
                'item' => '/evil.zip',
                'destination' => '/',
            ]);
        } catch (\Exception $e) {
            // tolerated — see above
        }

        // Security invariant: nothing escaped John's homedir.
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/jane/evil.txt');
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/escape.txt');

        // Jane, listing her own homedir, must not see the smuggled file.
        $this->signIn('jane@example.com', 'jane123');
        $this->sendRequest('POST', '/getdir', ['dir' => '/']);
        $this->assertOk();
        $this->assertStringNotContainsString('evil.txt', $this->response->getContent());
    }

    public function testDownloadStreamsExactFileBytes()
    {
        // The download endpoint is otherwise asserted only via headers; the
        // streamed body is never checked. Capture the callback output and
        // compare byte-for-byte (including a NUL and high bytes) against source.
        $this->signIn('john@example.com', 'john123');
        mkdir(TEST_REPOSITORY.'/john');
        $contents = "the quick brown fox\n\x00\x01\x02\xff binary-ish tail";
        file_put_contents(TEST_REPOSITORY.'/john/payload.bin', $contents);

        $this->sendRequest('GET', '/download', ['path' => base64_encode('/payload.bin')]);

        // Two nested output buffers: the callback's own ob_flush() moves data
        // from the inner buffer into the outer one we read from.
        ob_start();
        ob_start();
        $this->streamedResponse->sendContent();
        ob_end_flush();
        $body = ob_get_clean();

        $this->assertSame($contents, $body);
        $this->assertEquals(strlen($contents), $this->streamedResponse->headers->get('content-length'));
    }

    public function testDownloadMultipleItems()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/john.txt', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/john/johnsub');
        touch(TEST_REPOSITORY.'/john/johnsub/sub.txt', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/john/johnsub/sub2');

        $items = [
            0 => [
                'type' => 'dir',
                'path' => '/johnsub',
                'name' => 'johnsub',
                'time' => $this->timestamp,
            ],
            1 => [
                'type' => 'file',
                'path' => '/john.txt',
                'name' => 'john.txt',
                'time' => $this->timestamp,
            ],
        ];

        $this->sendRequest('POST', '/batchdownload', [
            'items' => $items,
        ]);

        $this->assertOk();

        $res = json_decode($this->response->getContent());
        $uniqid = $res->data->uniqid;

        $this->sendRequest('GET', '/batchdownload', [
            'uniqid' => $uniqid,
        ]);

        $this->assertOk();

        // test headers
        $this->response->getContent();
        $headers = $this->streamedResponse->headers;
        $this->assertEquals('application/octet-stream', $headers->get('content-type'));
        $this->assertEquals('attachment; filename=archive.zip', $headers->get('content-disposition'));
        $this->assertEquals('binary', $headers->get('content-transfer-encoding'));
        $this->assertEquals(414, $headers->get('content-length'));
    }

    public function testBatchArchiveCannotBeDownloadedByAnotherUser()
    {
        // IDOR regression: an archive created by one user must not be
        // retrievable by a different user who supplies its id. Both john and
        // admin hold the batchdownload permission, so the route guard passes
        // for both — the per-session ownership binding in the controller is
        // what must reject the cross-user download (returning 404, never the
        // bytes).
        $this->signIn('john@example.com', 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/secret.txt', $this->timestamp);

        $this->sendRequest('POST', '/batchdownload', [
            'items' => [
                [
                    'type' => 'file',
                    'path' => '/secret.txt',
                    'name' => 'secret.txt',
                    'time' => $this->timestamp,
                ],
            ],
        ]);
        $this->assertOk();
        $uniqid = json_decode($this->response->getContent())->data->uniqid;

        // A different authenticated user (also holding batchdownload) tries to
        // grab john's archive by its id.
        $this->signIn('admin@example.com', 'admin123');
        $this->sendRequest('GET', '/batchdownload', [
            'uniqid' => $uniqid,
        ]);
        $this->assertStatus(404);
    }

    public function testUpdateFileContent()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        file_put_contents(TEST_REPOSITORY.'/john/john.txt', 'lorem ipsum');

        $this->sendRequest('POST', '/savecontent', [
            'name' => 'john.txt',
            'content' => 'lorem ipsum new',
        ]);

        $this->assertOk();

        $updated = file_get_contents(TEST_REPOSITORY.'/john/john.txt');

        $this->assertEquals('lorem ipsum new', $updated);
    }

    public function testUpdateFileContentInSubDir()
    {
        $username = 'john@example.com';
        $this->signIn($username, 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        mkdir(TEST_REPOSITORY.'/john/sub');
        file_put_contents(TEST_REPOSITORY.'/john/sub/john.txt', 'lorem ipsum');

        $this->sendRequest('POST', '/changedir', [
            'to' => '/sub/',
        ]);

        $this->sendRequest('POST', '/savecontent', [
            'name' => 'john.txt',
            'content' => 'lorem ipsum new',
        ]);

        $this->assertOk();

        $updated = file_get_contents(TEST_REPOSITORY.'/john/sub/john.txt');

        $this->assertEquals('lorem ipsum new', $updated);
    }

    // --------------------------------------------------------------------
    // Cross-user folder isolation — characterization pins.
    //
    // Today FileController constructs a single path prefix from the user's
    // homedir; every storage operation runs through Filesystem::applyPathPrefix
    // which collapses any `..` path back to the prefix root. These tests pin
    // that contract: a user A authenticated session cannot leak filesystem
    // contents from another user B's homedir, regardless of the crafted path
    // shape (absolute, traversal, mixed). This is the safety net the upcoming
    // multi-folder refactor must preserve.
    // --------------------------------------------------------------------

    protected function seedJohnAndJaneWithSecret(): void
    {
        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/john-public.txt', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/jane');
        file_put_contents(TEST_REPOSITORY.'/jane/secret.txt', 'jane-private-payload');
    }

    public function testJohnCannotListJanesHomedirViaChangedirAbsolute()
    {
        $this->signIn('john@example.com', 'john123');
        $this->seedJohnAndJaneWithSecret();

        // Absolute path pointing at jane's homedir. applyPathPrefix joins it
        // under john's prefix → /john/jane → doesn't exist → empty listing.
        $this->sendRequest('POST', '/changedir', ['to' => '/jane']);

        $body = (string) $this->response->getContent();
        $this->assertStringNotContainsString('secret.txt', $body);
        $this->assertStringNotContainsString('jane-private-payload', $body);
    }

    public function testJohnCannotListJanesHomedirViaChangedirTraversal()
    {
        $this->signIn('john@example.com', 'john123');
        $this->seedJohnAndJaneWithSecret();

        // ../../jane and friends — Filesystem::applyPathPrefix collapses any
        // `..` segment to the prefix root.
        foreach (['../jane', '/../jane', '../../jane', '/../../jane'] as $path) {
            $this->sendRequest('POST', '/changedir', ['to' => $path]);
            $body = (string) $this->response->getContent();
            $this->assertStringNotContainsString(
                'secret.txt',
                $body,
                "Traversal path {$path} leaked jane's secret.txt"
            );
        }
    }

    public function testJohnCannotListJanesHomedirViaGetdir()
    {
        $this->signIn('john@example.com', 'john123');
        $this->seedJohnAndJaneWithSecret();

        foreach (['/jane', '../jane', '/../jane'] as $path) {
            $this->sendRequest('POST', '/getdir', ['dir' => $path]);
            $body = (string) $this->response->getContent();
            $this->assertStringNotContainsString(
                'secret.txt',
                $body,
                "getdir path {$path} leaked jane's secret.txt"
            );
        }
    }

    public function testJohnCannotReadJanesFileViaDownload()
    {
        $this->signIn('john@example.com', 'john123');
        $this->seedJohnAndJaneWithSecret();

        // Crafted relative + absolute paths; the download endpoint base64-decodes
        // the `path` query value and feeds it through Filesystem.
        foreach (['../jane/secret.txt', '/jane/secret.txt', '/../jane/secret.txt'] as $craft) {
            $path_encoded = base64_encode($craft);
            $this->sendRequest('GET', '/download&path='.$path_encoded);

            // Either a redirect (download-missing path) or any non-200 is fine —
            // the failure case is a 200 carrying jane's content.
            if ($this->response->isOk()) {
                // If somehow OK, body must not contain jane's payload.
                $body = (string) $this->response->getContent();
                $this->assertStringNotContainsString('jane-private-payload', $body, "Crafted path {$craft} leaked jane's content");
            }

            // jane's file must still exist on disk untouched.
            $this->assertSame('jane-private-payload', file_get_contents(TEST_REPOSITORY.'/jane/secret.txt'));
        }
    }

    public function testJohnCannotWriteIntoJanesHomedirViaSaveContent()
    {
        $this->signIn('john@example.com', 'john123');
        $this->seedJohnAndJaneWithSecret();

        // Crafted name with `..` — escapeDots() should strip the traversal
        // segment so it lands under /john, not /jane.
        try {
            $this->sendRequest('POST', '/savecontent', [
                'name' => '../jane/secret.txt',
                'content' => 'overwritten-by-john',
            ]);
        } catch (Exception $e) {
            // Acceptable: any thrown exception means the write didn't land.
        }

        // jane's file must be untouched.
        $this->assertSame('jane-private-payload', file_get_contents(TEST_REPOSITORY.'/jane/secret.txt'));
    }

    public function testJohnCannotRenameAcrossHomedirs()
    {
        $this->signIn('john@example.com', 'john123');
        $this->seedJohnAndJaneWithSecret();

        try {
            $this->sendRequest('POST', '/renameitem', [
                'from' => '/jane/secret.txt',
                'to'   => '/john-public.txt',
            ]);
        } catch (Exception $e) {
            // expected — source doesn't exist inside john's prefix.
        }

        $this->assertFileExists(TEST_REPOSITORY.'/jane/secret.txt');
        $this->assertSame('jane-private-payload', file_get_contents(TEST_REPOSITORY.'/jane/secret.txt'));
    }

    public function testJohnCannotMoveItemsIntoJanesHomedir()
    {
        $this->signIn('john@example.com', 'john123');
        $this->seedJohnAndJaneWithSecret();

        try {
            $this->sendRequest('POST', '/moveitems', [
                'items' => [
                    ['type' => 'file', 'path' => '/john-public.txt', 'name' => 'john-public.txt', 'time' => $this->timestamp],
                ],
                'destination' => '../jane',
            ]);
        } catch (Exception $e) {
        }

        // applyPathPrefix collapses `../` so destination becomes /john root.
        // The file may have been moved within john's homedir, but it must NOT
        // have landed inside jane's actual /jane folder.
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/jane/john-public.txt');
        // jane's secret stays intact regardless.
        $this->assertFileExists(TEST_REPOSITORY.'/jane/secret.txt');
    }

    public function testJohnCannotCopyJanesFileIntoOwnHomedir()
    {
        $this->signIn('john@example.com', 'john123');
        $this->seedJohnAndJaneWithSecret();

        try {
            $this->sendRequest('POST', '/copyitems', [
                'items' => [
                    ['type' => 'file', 'path' => '/jane/secret.txt', 'name' => 'secret.txt', 'time' => $this->timestamp],
                ],
                'destination' => '/',
            ]);
        } catch (Exception $e) {
            // expected — source path doesn't resolve inside john's prefix.
        }

        // jane's secret content must not have been duplicated into /john.
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/john/secret.txt');
        $this->assertSame('jane-private-payload', file_get_contents(TEST_REPOSITORY.'/jane/secret.txt'));
    }

    public function testChangedirTraversalCollapsesToHomedirRoot()
    {
        $this->signIn('john@example.com', 'john123');
        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/marker.txt', $this->timestamp);
        mkdir(TEST_REPOSITORY.'/elsewhere');
        touch(TEST_REPOSITORY.'/elsewhere/elsewhere.txt', $this->timestamp);

        // All these escape attempts should collapse back to john's root, NOT
        // surface /elsewhere or any sibling content.
        foreach (['..', '../', '/../', '../..', '/../../', '../../../etc'] as $craft) {
            $this->sendRequest('POST', '/changedir', ['to' => $craft]);
            $body = (string) $this->response->getContent();

            $this->assertStringContainsString('marker.txt', $body, "Crafted path {$craft} did not resolve to john's root");
            $this->assertStringNotContainsString('elsewhere.txt', $body, "Crafted path {$craft} leaked sibling content");
        }
    }

    public function testSessionCwdIsolatedAcrossUsers()
    {
        // Pin the invariant that one user's SESSION_CWD cannot affect another
        // user's session. signIn() creates a fresh session, but the contract
        // is worth pinning so the multi-folder refactor (which adds
        // SESSION_ACTIVE_HOMEDIR alongside SESSION_CWD) can't accidentally
        // start sharing state across users.
        $this->signIn('john@example.com', 'john123');
        mkdir(TEST_REPOSITORY.'/john');
        mkdir(TEST_REPOSITORY.'/john/johnsub');
        $this->sendRequest('POST', '/changedir', ['to' => '/johnsub']);
        $this->assertOk();

        // Switch users — fresh session.
        $this->signIn('jane@example.com', 'jane123');
        mkdir(TEST_REPOSITORY.'/jane');
        touch(TEST_REPOSITORY.'/jane/jane-marker.txt', $this->timestamp);

        $this->sendRequest('POST', '/getdir');
        $body = (string) $this->response->getContent();

        // jane gets her own homedir root, not whatever john last set as cwd.
        $this->assertStringContainsString('jane-marker.txt', $body);
        $this->assertStringNotContainsString('johnsub', $body);
    }

    // --------------------------------------------------------------------
    // Multi-folder active-folder session contract (Phase 3).
    //
    // The multi-folder user has homedirs ['/multiA', '/multiB']. They MUST
    // pick a folder via POST /selectfolder before any file-op endpoint
    // accepts their request. Single-folder users continue to work without
    // any selection step — ensureActiveHomedir auto-seeds for them.
    // --------------------------------------------------------------------

    protected function seedMultiFolderOnDisk(): void
    {
        mkdir(TEST_REPOSITORY.'/multiA');
        mkdir(TEST_REPOSITORY.'/multiB');
        touch(TEST_REPOSITORY.'/multiA/in-a.txt', $this->timestamp);
        touch(TEST_REPOSITORY.'/multiB/in-b.txt', $this->timestamp);
    }

    public function testSingleFolderUserHasActiveHomedirAutoSetOnLogin()
    {
        // John has one folder. He should be able to list immediately after
        // login without an explicit selectFolder call.
        $this->signIn('john@example.com', 'john123');
        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/auto.txt', $this->timestamp);

        $this->sendRequest('POST', '/getdir', ['dir' => '/']);
        $this->assertOk();

        $body = (string) $this->response->getContent();
        $this->assertStringContainsString('auto.txt', $body);
    }

    public function testMultiFolderUserWithNoActiveHomedirGetsErrorOnFileOps()
    {
        // signIn() goes through verifyPasswordOnly + establishSessionFor,
        // not through the real /login endpoint, so seedActiveHomedirAfterLogin
        // never fires — multi-folder user lands with no active selection.
        $this->signIn('multi@example.com', 'multi123');
        $this->seedMultiFolderOnDisk();

        $this->sendRequest('POST', '/getdir', ['dir' => '/']);
        // Must be a clean 422, NOT a constructor-thrown 500.
        $this->assertStatus(422);
    }

    public function testSelectFolderRejectsPathNotInHomedirs()
    {
        $this->signIn('multi@example.com', 'multi123');

        $this->sendRequest('POST', '/selectfolder', ['homedir' => '/notmine']);
        $this->assertStatus(422);
    }

    public function testSelectFolderRequiresHomedirField()
    {
        $this->signIn('multi@example.com', 'multi123');

        $this->sendRequest('POST', '/selectfolder');
        $this->assertStatus(422);
    }

    public function testSelectFolderAcceptsValidFolderAndSwitchesPrefix()
    {
        $this->signIn('multi@example.com', 'multi123');
        $this->seedMultiFolderOnDisk();

        $this->sendRequest('POST', '/selectfolder', ['homedir' => '/multiA']);
        $this->assertOk();
        $this->assertResponseJsonHas(['data' => ['active_homedir' => '/multiA']]);

        $this->sendRequest('POST', '/getdir', ['dir' => '/']);
        $this->assertOk();
        $body = (string) $this->response->getContent();
        $this->assertStringContainsString('in-a.txt', $body);
        $this->assertStringNotContainsString('in-b.txt', $body);

        // Switch to the other folder.
        $this->sendRequest('POST', '/selectfolder', ['homedir' => '/multiB']);
        $this->assertOk();

        $this->sendRequest('POST', '/getdir', ['dir' => '/']);
        $body = (string) $this->response->getContent();
        $this->assertStringContainsString('in-b.txt', $body);
        $this->assertStringNotContainsString('in-a.txt', $body);
    }

    public function testSelectFolderResetsCwdToRootOnSwitch()
    {
        $this->signIn('multi@example.com', 'multi123');
        $this->seedMultiFolderOnDisk();
        mkdir(TEST_REPOSITORY.'/multiA/deep');
        touch(TEST_REPOSITORY.'/multiA/deep/inner.txt', $this->timestamp);

        $this->sendRequest('POST', '/selectfolder', ['homedir' => '/multiA']);
        $this->sendRequest('POST', '/changedir', ['to' => '/deep']);
        $this->assertOk();

        // Switch — CWD must reset to root of the new folder.
        $this->sendRequest('POST', '/selectfolder', ['homedir' => '/multiB']);

        $this->sendRequest('POST', '/getdir');
        $body = (string) $this->response->getContent();
        // Listing must be of /multiB root, not the prior /deep path.
        $this->assertStringContainsString('in-b.txt', $body);
        $this->assertStringNotContainsString('inner.txt', $body);
    }

    public function testActiveHomedirInvariantAcrossOperations()
    {
        $this->signIn('multi@example.com', 'multi123');
        $this->seedMultiFolderOnDisk();

        // Seed the marker file in multiA *directly on disk* (the savecontent
        // endpoint requires the file to pre-exist to support its delete-then-
        // store overwrite path). The test still exercises the controller's
        // active-homedir resolution by listing through getdir.
        file_put_contents(TEST_REPOSITORY.'/multiA/isolation-marker.txt', 'should-only-exist-in-multiA');

        $this->sendRequest('POST', '/selectfolder', ['homedir' => '/multiA']);
        $this->sendRequest('POST', '/getdir');
        $body = (string) $this->response->getContent();
        $this->assertStringContainsString('isolation-marker.txt', $body);

        // Switch to multiB and list — must NOT see the marker from multiA.
        $this->sendRequest('POST', '/selectfolder', ['homedir' => '/multiB']);
        $this->sendRequest('POST', '/getdir');
        $body = (string) $this->response->getContent();
        $this->assertStringNotContainsString('isolation-marker.txt', $body);

        // And the file is physically inside multiA, not multiB.
        $this->assertFileExists(TEST_REPOSITORY.'/multiA/isolation-marker.txt');
        $this->assertFileDoesNotExist(TEST_REPOSITORY.'/multiB/isolation-marker.txt');
    }

    // --------------------------------------------------------------------
    // chmod operation. No default-fixture user carries the 'chmod'
    // permission, so we add a chmod-capable user at runtime (via the live
    // auth adapter) rather than mutating the shared MockUsers fixture.
    // --------------------------------------------------------------------

    public function testChmodItemsChangesFilePermissions()
    {
        // Provision a chmod-capable user without touching the shared fixture.
        $app = $this->sendRequest('GET', '/getuser');
        $auth = $app->resolve(AuthInterface::class);

        $cu = new User();
        $cu->setRole('user');
        $cu->setHomedir('/cu');
        $cu->setUsername('cu@example.com');
        $cu->setName('Chmod User');
        $cu->setPermissions(['read', 'write', 'chmod']);
        $auth->add($cu, 'cu12345');

        $this->signIn('cu@example.com', 'cu12345');

        mkdir(TEST_REPOSITORY.'/cu');
        $target = TEST_REPOSITORY.'/cu/perm.txt';
        touch($target);
        chmod($target, 0644);

        $this->sendRequest('POST', '/chmoditems', [
            'items' => [
                0 => [
                    'type' => 'file',
                    'path' => '/perm.txt',
                    'name' => 'perm.txt',
                ],
            ],
            'permissions' => '0600',
        ]);

        $this->assertOk();
        clearstatcache();
        $this->assertSame('0600', substr(sprintf('%o', fileperms($target)), -4));
    }

    public function testChmodItemsRejectedWithoutChmodPermission()
    {
        // john has read/write/upload/download/batchdownload but NOT chmod.
        $this->signIn('john@example.com', 'john123');

        mkdir(TEST_REPOSITORY.'/john');
        touch(TEST_REPOSITORY.'/john/perm.txt');

        $this->sendRequest('POST', '/chmoditems', [
            'items' => [
                0 => ['type' => 'file', 'path' => '/perm.txt', 'name' => 'perm.txt'],
            ],
            'permissions' => '0600',
        ]);

        $this->assertStatus(404);
    }

    // --------------------------------------------------------------------
    // Per-operation permission enforcement. jane has only ['read','write'],
    // so operations gated on zip / batchdownload must be rejected for her
    // (the router fails closed with a 404 before the controller runs).
    // --------------------------------------------------------------------

    public function testUserWithoutZipPermissionCannotZipItems()
    {
        $this->signIn('jane@example.com', 'jane123');

        mkdir(TEST_REPOSITORY.'/jane');
        touch(TEST_REPOSITORY.'/jane/jane.txt', $this->timestamp);

        $this->sendRequest('POST', '/zipitems', [
            'items' => [
                0 => ['type' => 'file', 'path' => '/jane.txt', 'name' => 'jane.txt'],
            ],
            'destination' => '/',
            'name' => 'archive.zip',
        ]);

        $this->assertStatus(404);
    }

    public function testUserWithoutZipPermissionCannotUnzipItem()
    {
        $this->signIn('jane@example.com', 'jane123');

        $this->sendRequest('POST', '/unzipitem', [
            'item' => '/archive.zip',
            'destination' => '/',
        ]);

        $this->assertStatus(404);
    }

    public function testUserWithoutBatchdownloadPermissionCannotBatchDownload()
    {
        $this->signIn('jane@example.com', 'jane123');

        mkdir(TEST_REPOSITORY.'/jane');
        touch(TEST_REPOSITORY.'/jane/jane.txt', $this->timestamp);

        $this->sendRequest('POST', '/batchdownload', [
            'items' => [
                0 => ['type' => 'file', 'path' => '/jane.txt', 'name' => 'jane.txt'],
            ],
        ]);

        $this->assertStatus(404);
    }
}
