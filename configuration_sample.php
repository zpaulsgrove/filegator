<?php

return [
    'public_path' => APP_PUBLIC_PATH,
    'public_dir' => APP_PUBLIC_DIR,
    'overwrite_on_upload' => false,
    'timezone' => 'UTC', // https://www.php.net/manual/en/timezones.php
    'download_inline' => ['pdf'], // download inline in the browser, array of extensions, use * for all
    'lockout_attempts' => 5, // max failed login attempts before ip lockout
    'lockout_timeout' => 15, // ip lockout timeout in seconds

    'mfa_required_for_admins' => true,           // admins must enroll TOTP on first login
    'step_up_auth' => true,                      // require admin password+TOTP re-verify on user CRUD / reset-MFA
    'mfa_pending_bind_ua' => true,               // reject /login/mfa if User-Agent differs from /login
    'mfa_pending_bind_ip_prefix' => null,        // 'exact', '/24', '/48', or null to disable IP binding
    'password_reset_token_ttl' => 3600,           // seconds the reset link stays valid
    // Coarse per-network throttle. An office (and tools like Windows Sandbox)
    // shares one public IP, so a low value blocks every user behind that NAT
    // after just a few attempts — keep this generous.
    'password_reset_max_per_hour_per_ip' => 30,
    // The real abuse bound: caps reset emails sent to any single address, so a
    // generous per-IP ceiling can't be used to mail-bomb a known mailbox.
    'password_reset_max_per_day_per_email' => 5,

    'frontend_config' => [
        'app_name' => 'FileGator',
        'app_version' => APP_VERSION,
        'language' => 'english',
        'logo' => 'https://filegator.io/filegator_logo.svg',
        'upload_max_size' => 100 * 1024 * 1024, // 100MB
        'upload_chunk_size' => 1 * 1024 * 1024, // 1MB
        'upload_simultaneous' => 3,
        'default_archive_name' => 'archive.zip',
        'editable' => ['.txt', '.css', '.js', '.ts', '.html', '.php', '.json', '.md'],
        'date_format' => 'MM/DD/YY hh:mm:ss', // US convention (MM/DD/YY); see: https://momentjs.com/docs/#/displaying/format/
        'guest_redirection' => '', // useful for external auth adapters
        'search_simultaneous' => 5,
        'filter_entries' => [],
        'pagination' => ['', 5, 10, 15],
    ],

    'services' => [
        'Filegator\Services\Logger\LoggerInterface' => [
            'handler' => '\Filegator\Services\Logger\Adapters\MonoLogger',
            'config' => [
                'monolog_handlers' => [
                    function () {
                        return new \Monolog\Handler\StreamHandler(
                            __DIR__.'/private/logs/app.log',
                            // Quieter in production: WARNING and above avoids
                            // persisting routine request detail to disk, while
                            // development keeps DEBUG for diagnostics.
                            APP_ENV == 'production' ? \Monolog\Logger::WARNING : \Monolog\Logger::DEBUG
                        );
                    },
                ],
            ],
        ],
        'Filegator\Services\Session\SessionStorageInterface' => [
            'handler' => '\Filegator\Services\Session\Adapters\SessionStorage',
            'config' => [
                'handler' => function () {
                    $save_path = null; // use default system path
                    //$save_path = __DIR__.'/private/sessions';
                    $handler = new \Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler($save_path);

                    return new \Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage([
                            "cookie_samesite" => "Lax",
                            // Emit the Secure flag whenever the request arrives over
                            // HTTPS — directly, or via a TLS-terminating proxy that
                            // forwards X-Forwarded-Proto. Computed (rather than a hard
                            // true) so plain-HTTP demos keep working while the session
                            // cookie is never sent in cleartext on a TLS deployment.
                            "cookie_secure" => (
                                (! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
                            ),
                            "cookie_httponly" => true,
                            "gc_maxlifetime" => 3600, // idle session timeout in seconds (60 min); resets on each request
                        ], $handler);
                },
            ],
        ],
        'Filegator\Services\Cors\Cors' => [
            'handler' => '\Filegator\Services\Cors\Cors',
            'config' => [
                'enabled' => APP_ENV == 'production' ? false : true,
                // Origins permitted to make credentialed cross-origin requests.
                // Leave empty to reflect any Origin (convenient for same-machine
                // development). If you enable CORS in production you MUST list the
                // exact front-end origins here, e.g.
                // ['https://app.example.com'] — otherwise any site could issue
                // authenticated requests on a logged-in user's behalf.
                'allowed_origins' => [],
            ],
        ],
        'Filegator\Services\Tmpfs\TmpfsInterface' => [
            'handler' => '\Filegator\Services\Tmpfs\Adapters\Tmpfs',
            'config' => [
                'path' => __DIR__.'/private/tmp/',
                'gc_probability_perc' => 10,
                'gc_older_than' => 60 * 60 * 24 * 2, // 2 days
            ],
        ],
        'Filegator\Services\Security\Security' => [
            'handler' => '\Filegator\Services\Security\Security',
            'config' => [
                'csrf_protection' => true,
                'csrf_key' => "123456", // randomize this
                'csrf_exempt_paths' => ['/password/forgot', '/password/reset/validate', '/password/reset'],
                'ip_allowlist' => [],
                'ip_denylist' => [],
                'allow_insecure_overlays' => false,
            ],
        ],
        'Filegator\Services\View\ViewInterface' => [
            'handler' => '\Filegator\Services\View\Adapters\Vuejs',
            'config' => [
                'add_to_head' => '',
                'add_to_body' => '',
            ],
        ],
        'Filegator\Services\Storage\Filesystem' => [
            'handler' => '\Filegator\Services\Storage\Filesystem',
            'config' => [
                'separator' => '/',
                'config' => [],
                'adapter' => function () {
                    return new \League\Flysystem\Adapter\Local(
                        __DIR__.'/repository'
                    );
                },
            ],
        ],
        'Filegator\Services\Archiver\ArchiverInterface' => [
            'handler' => '\Filegator\Services\Archiver\Adapters\ZipArchiver',
            'config' => [],
        ],
        'Filegator\Services\Auth\AuthInterface' => [
            'handler' => '\Filegator\Services\Auth\Adapters\JsonFile',
            'config' => [
                'file' => __DIR__.'/private/users.json',
            ],
        ],
        'Filegator\Services\Auth\MfaLockout' => [
            'handler' => '\Filegator\Services\Auth\MfaLockout',
            'config' => [],
        ],
        // Mailer / Mfa / PasswordReset must come BEFORE Router. Router::init
        // dispatches the route immediately, so any controller method that
        // type-hints these services (e.g. ViewController::getFrontendConfig)
        // would otherwise fail to resolve them.
        'Filegator\Services\Mailer\MailerInterface' => [
            'handler' => '\Filegator\Services\Mailer\Adapters\SymfonyMailer',
            'config' => [
                // Symfony Mailer DSN. Use 'null://null' to disable sending (feature stays hidden).
                // Examples:
                //   'postmark+api://POSTMARK_SERVER_TOKEN@default'   (recommended; uses symfony/postmark-mailer, already required)
                //   'postmark+smtp://POSTMARK_SERVER_TOKEN@default'
                //   'smtp://user:pass@smtp.example.com:587?encryption=tls'
                //   'sendmail://default'
                // NB: with Postmark, every From: address below (and the AuditMailer one)
                // must be a verified Sender Signature / on a verified domain, or sends fail.
                'dsn' => 'null://null',
                'from_email' => 'no-reply@example.com',
                'from_name' => 'FileGator',
                // Hard cap (seconds) we force on every SMTP socket so a slow / unreachable
                // mail server cannot hang a PHP-FPM worker for PHP's default_socket_timeout
                // (60s by default). Appended to the DSN automatically if not already set,
                // and also enforced via a per-request default_socket_timeout clamp. Tune up
                // for very slow servers; do not set to 0.
                'timeout' => 5,
            ],
        ],
        'Filegator\Services\Mfa\MfaSecretCrypto' => [
            'handler' => '\Filegator\Services\Mfa\MfaSecretCrypto',
            'config' => [
                // 32-byte sodium secretbox key. Auto-generated on first use,
                // mode 0600. Back up alongside users.json — losing one without
                // the other makes every enrolled TOTP secret unrecoverable.
                'key_path' => __DIR__.'/private/mfa_encryption.key',
            ],
        ],
        'Filegator\Services\Mfa\MfaService' => [
            'handler' => '\Filegator\Services\Mfa\MfaService',
            'config' => [
                'issuer' => 'FileGator',
            ],
        ],
        'Filegator\Services\PasswordReset\PasswordResetService' => [
            'handler' => '\Filegator\Services\PasswordReset\PasswordResetService',
            'config' => [
                'token_file' => __DIR__.'/private/password_resets.json',
                'reset_subject' => 'Reset your FileGator password',
                // REQUIRED for password reset to work. Must be the full public URL
                // operators want reset links to point to (scheme + host + base path).
                // We deliberately do NOT derive this from the request Host header,
                // because doing so allows an attacker to send victims reset links
                // pointing at an attacker-controlled host.
                // Set to null (default) to disable the password-reset feature.
                'reset_url_base' => null, // e.g. 'https://files.example.com/'
                // Optional per-deployment branding for the reset email.
                // Any value omitted falls back to a neutral default.
                'branding' => [
                    // 'app_label'     => 'My Portal',                       // shown in subject + body
                    // 'logo_url'      => 'https://my.example.com/logo.png', // header image; omitted if blank
                    // 'primary_color' => '#2c7a7b',                         // button + accent strip
                    // 'background'    => '#f4f4f5',                         // page background
                    // 'support_email' => 'support@example.com',             // footer mailto
                ],
            ],
        ],
        // Operational alert emails for admin user mutations and self-disabled
        // MFA. Leave 'recipient' empty (or 'enabled' => false) to silence.
        'Filegator\Services\Audit\AuditMailer' => [
            'handler' => '\Filegator\Services\Audit\AuditMailer',
            'config' => [
                'recipient' => '', // e.g. 'audit@example.com'
                'from_email' => '', // distinct from the transactional From: above
                'from_name' => '',
                'app_label' => 'FileGator portal', // shown in email body header
                'enabled' => true,
            ],
        ],
        // Weekly all-users snapshot. Composes via AuditMailer using the same
        // recipient/From: pair above. Fires when the first admin loads
        // /listusers after the interval has elapsed since the last send.
        // Set state_file to null (or leave handler unregistered) to disable.
        'Filegator\Services\Audit\WeeklyDigest' => [
            'handler' => '\Filegator\Services\Audit\WeeklyDigest',
            'config' => [
                'state_file' => __DIR__.'/private/audit_state.json',
                'interval_seconds' => 604800, // 7 days
            ],
        ],
        // Admin-visible file-activity log (uploads, deletes, moves, renames,
        // etc. across all users/folders). Records username, role, client IP,
        // and the root-relative path of each write — i.e. PII. Each line is
        // encrypted at rest with the dedicated key below (libsodium), so a
        // leaked log file/backup is useless without the key — BACK THE KEY UP
        // SEPARATELY: lose it and the history is unrecoverable. Entries older
        // than `max_age_days` are physically purged. Leave this block
        // unregistered to disable the feature (it then safely no-ops).
        'Filegator\Services\Audit\AuditLog' => [
            'handler' => '\Filegator\Services\Audit\AuditLog',
            'config' => [
                'log_file' => __DIR__.'/private/audit_log.jsonl',
                'key_path' => __DIR__.'/private/audit_encryption.key', // 0600, auto-generated
                'max_age_days' => 30, // entries older than this are purged on write
            ],
        ],
        'Filegator\Services\Router\Router' => [
            'handler' => '\Filegator\Services\Router\Router',
            'config' => [
                'query_param' => 'r',
                'routes_file' => __DIR__.'/backend/Controllers/routes.php',
            ],
        ],
    ],
];
