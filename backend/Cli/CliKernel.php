<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Cli;

use Filegator\Config\Config;
use Filegator\Container\Container;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Kernel\StreamedResponse;
use Filegator\Services\Audit\AuditLog;
use Filegator\Services\Audit\MonthlyReport;
use Filegator\Services\Audit\ReportStore;

/**
 * Boots just enough of the container to run scheduled jobs outside HTTP.
 *
 * Deliberately does NOT construct Filegator\App. App's constructor catches
 * every boot Throwable and ends in a bare `die;`, which exits with status ZERO.
 * Under cron that turns any boot failure — an unwritable private/, a bad key
 * path, a malformed configuration.php — into a run that looks successful
 * forever, while printing a 500 JSON body into the cron log. For a job whose
 * whole purpose is producing a compliance artifact, silence is the worst
 * possible failure mode, so the boot loop is reproduced here inside a try/catch
 * that can actually report.
 *
 * Services are chosen by ALLOWLIST rather than by removing the HTTP ones. A
 * denylist silently admits whatever service a future release adds, and the
 * failure mode of admitting the wrong one is not a crash — see
 * filterServices() for why that matters.
 */
class CliKernel
{
    const EXIT_OK = 0;

    const EXIT_FAILED = 1;

    const EXIT_MISCONFIGURED = 2;

    /**
     * The only services a scheduled report job needs.
     *
     * Excluded, and why each one matters:
     *
     *  - Router: init() does not merely register routes, it DISPATCHES
     *    immediately. With no ?r= parameter the uri defaults to '/', so booting
     *    it would run a web controller and render the app HTML into the cron
     *    log.
     *  - SessionStorageInterface / Security: both touch $request->getSession(),
     *    native PHP sessions and CSRF token storage, and Security can exit()
     *    on its ip_allowlist path.
     *  - Cors / ViewInterface: write response headers.
     *  - AuthInterface: deliberately absent. Nothing in the report path needs a
     *    user, so the CLI structurally cannot act as one — and JsonFile's
     *    constructor type-hints the session, so including it would drag the
     *    excluded session service back in and fail to resolve anyway.
     */
    const SERVICES = [
        'Filegator\Services\Logger\LoggerInterface',
        'Filegator\Services\Mailer\MailerInterface',
        'Filegator\Services\Audit\AuditMailer',
        'Filegator\Services\Audit\AuditLog',
        'Filegator\Services\Audit\ReportStore',
        'Filegator\Services\Audit\MonthlyReport',
    ];

    /**
     * Narrow a services config to the allowlist, preserving its original order.
     *
     * Order matters: App-style boot constructs and init()s in array order, and
     * a later service's constructor may type-hint an earlier alias.
     */
    public static function filterServices(array $services): array
    {
        $filtered = [];
        foreach ($services as $key => $definition) {
            if (in_array(ltrim($key, '\\'), self::SERVICES, true)) {
                $filtered[$key] = $definition;
            }
        }

        return $filtered;
    }

    /**
     * Build a container from an already-filtered config.
     *
     * @throws \Throwable so the caller can report and exit non-zero
     */
    public static function boot(array $rawConfig): Container
    {
        // Filter only the services key: passing the services array alone would
        // drop top-level settings, and Config silently defaults `timezone` to
        // UTC — which would make the cron's month boundaries disagree with the
        // web app's on any non-UTC deployment.
        $rawConfig['services'] = self::filterServices($rawConfig['services'] ?? []);

        $config = new Config($rawConfig);
        $container = new Container();

        $container->set(Config::class, $config);
        $container->set(Container::class, $container);
        $container->set(Request::class, new Request());
        $container->set(Response::class, new Response());
        $container->set(StreamedResponse::class, new StreamedResponse());

        foreach ($config->get('services', []) as $key => $service) {
            $container->set($key, $container->get($service['handler']));
            $container->get($key)->init(isset($service['config']) ? $service['config'] : []);
        }

        return $container;
    }

    /**
     * @return int process exit code
     */
    public static function dispatch(Container $container, array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = self::parseOptions(array_slice($argv, 2));

        switch ($command) {
            case 'report:monthly':
                return self::reportMonthly($container, $options);

            case 'report:preflight':
                return self::reportPreflight($container);

            case 'report:status':
                return self::reportStatus($container);

            case 'help':
            case '--help':
            case '-h':
                self::usage();

                return self::EXIT_OK;

            default:
                fwrite(STDERR, "Unknown command: {$command}\n\n");
                self::usage();

                return self::EXIT_MISCONFIGURED;
        }
    }

    protected static function reportMonthly(Container $container, array $options): int
    {
        $report = $container->get(MonthlyReport::class);

        // An unregistered service still autowires, as a fresh un-init()'d
        // instance — so "not configured" must be checked explicitly or the job
        // no-ops while reporting success.
        if (! $report->isConfigured()) {
            fwrite(STDERR, "MonthlyReport is not configured. Register the service block in configuration.php.\n");

            return self::EXIT_MISCONFIGURED;
        }

        $results = $report->run($options['period'] ?? null, ! empty($options['force']));

        // null means the job could not run at all — a misconfigured AuditLog, or
        // a state file it cannot open. That must NOT be reported as success:
        // cron would go green forever while producing no reports, which is the
        // exact silent failure this entry point exists to prevent. An empty
        // array is different and legitimate: nothing was due.
        if ($results === null) {
            // The specific reason is logged at WARNING by the service — an
            // unconfigured AuditLog, an unwritable state file, or a --period
            // that has not closed yet. Naming only one of them here would be
            // actively misleading, so point at the log that has the real one.
            fwrite(STDERR, "Could not run. The reason was logged at WARNING — check private/logs/app.log.\n");

            return self::EXIT_MISCONFIGURED;
        }

        if ($results === []) {
            echo "Nothing due.\n";

            return self::EXIT_OK;
        }

        $exit = self::EXIT_OK;
        foreach ($results as $result) {
            printf("%s: %s%s\n", $result['period'], $result['status'], isset($result['report_id']) ? ' ('.$result['report_id'].')' : '');

            if ($result['status'] === MonthlyReport::STATUS_BLOCKED_COVERAGE) {
                // Actionable: the operator can raise max_age_days.
                $exit = self::EXIT_MISCONFIGURED;
            } elseif ($result['status'] === MonthlyReport::STATUS_UNRECOVERABLE) {
                // Terminal and NOT actionable — the events are gone. It is
                // recorded and logged once; failing the cron over it would make
                // every fresh install alert on its first run, for backfill
                // months that predate the deployment.
                continue;
            } elseif ($result['status'] !== MonthlyReport::STATUS_OK && $exit === self::EXIT_OK) {
                $exit = self::EXIT_FAILED;
            }
        }

        return $exit;
    }

    /**
     * Report what the job WOULD do, so operators find a misconfiguration at
     * install time rather than at audit time.
     */
    protected static function reportPreflight(Container $container): int
    {
        $report = $container->get(MonthlyReport::class);
        $audit = $container->get(AuditLog::class);
        $store = $container->get(ReportStore::class);

        $exit = self::EXIT_OK;

        $period = $report->periodFor(1);
        [$from, $to] = $report->windowFor($period);

        printf("Period to report:   %s\n", $period);
        printf("Window:             %s .. %s UTC\n", gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to));
        printf("MonthlyReport:      %s\n", $report->isConfigured() ? 'configured' : 'NOT CONFIGURED');
        printf("AuditLog:           %s\n", $audit->isConfigured() ? 'configured' : 'NOT CONFIGURED');
        printf("ReportStore:        %s\n", $store->isConfigured() ? 'configured' : 'NOT CONFIGURED');

        if (! $report->isConfigured() || ! $audit->isConfigured() || ! $store->isConfigured()) {
            $exit = self::EXIT_MISCONFIGURED;
        }

        if ($audit->isConfigured()) {
            $cutoff = $audit->retentionCutoff();
            printf("max_age_days:       %d\n", $audit->getMaxAgeDays());
            printf("Retention cutoff:   %s UTC\n", gmdate('Y-m-d H:i:s', $cutoff));

            if ($cutoff > $from) {
                $short = (int) ceil(($cutoff - $from) / 3600);
                printf(
                    "Coverage:           SHORT by %d hours — raise max_age_days to at least 32 (40 recommended)\n",
                    $short
                );
                $exit = self::EXIT_MISCONFIGURED;
            } else {
                printf("Coverage:           complete\n");
            }
        }

        $exit = self::checkOwnership() ? $exit : self::EXIT_MISCONFIGURED;

        return $exit;
    }

    /**
     * Warn when the cron user differs from the owner of private/.
     *
     * This is the most likely real-world failure and the most mundane. Running
     * as root on a fresh install creates audit_log.jsonl root-owned 0600, after
     * which the web process can never open it: recordMany() fails silently and
     * every file mutation goes unrecorded. The report key has the same problem
     * in reverse — the web download path then cannot decrypt.
     */
    protected static function checkOwnership(): bool
    {
        $private = dirname(__DIR__, 2).'/private';

        if (! function_exists('posix_geteuid') || ! is_dir($private)) {
            return true;
        }

        $owner = @fileowner($private);
        $euid = posix_geteuid();

        if ($owner === false || $owner === $euid) {
            printf("Runs as:            uid %d (matches private/)\n", $euid);

            return true;
        }

        fwrite(STDERR, sprintf(
            "Runs as:            uid %d but private/ is owned by uid %d — MISMATCH.\n"
            ."                    Run this job as the same user as PHP-FPM, or files it creates\n"
            ."                    (reports, keys) will be unreadable by the web process and vice versa.\n",
            $euid,
            $owner
        ));

        return false;
    }

    protected static function reportStatus(Container $container): int
    {
        $report = $container->get(MonthlyReport::class);
        $state = $report->readStateFile();
        $periods = $state['periods'] ?? [];

        if ($periods === []) {
            echo "No periods recorded yet.\n";

            return self::EXIT_OK;
        }

        ksort($periods);
        foreach ($periods as $period => $entry) {
            printf(
                "%s  %-18s events=%-7s coverage=%-9s attempts=%d\n",
                $period,
                $entry['status'] ?? '?',
                $entry['events'] ?? '-',
                $entry['coverage'] ?? '-',
                $entry['attempts'] ?? 0
            );
        }

        return self::EXIT_OK;
    }

    protected static function parseOptions(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if ($arg === '--force') {
                $options['force'] = true;
            } elseif (strpos($arg, '--period=') === 0) {
                $period = substr($arg, strlen('--period='));
                if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
                    fwrite(STDERR, "Invalid --period (expected YYYY-MM): {$period}\n");
                    exit(self::EXIT_MISCONFIGURED);
                }
                $options['period'] = $period;
            }
        }

        return $options;
    }

    protected static function usage(): void
    {
        echo <<<TXT
FileGator scheduled jobs.

Usage:  php bin/filegator <command> [options]

Commands:
  report:monthly     Generate any due monthly activity reports and notify admins.
                     Idempotent per calendar month — safe (and intended) to run
                     from cron DAILY, so a month that failed is retried rather
                     than lost.
  report:preflight   Show the window that would be reported, whether retention
                     can cover it, and whether this user can write private/.
                     Run after install and after changing max_age_days.
  report:status      Show what has been generated per period.

Options:
  --period=YYYY-MM   Restrict to one calendar month.
  --force            Regenerate even if the period is already complete.

Exit codes:  0 ok / nothing due   1 generation failed (retryable)   2 misconfigured

TXT;
    }
}
