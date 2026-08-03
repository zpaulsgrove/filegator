<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Feature;

use Filegator\Cli\CliKernel;
use Filegator\Services\Audit\MonthlyReport;
use Tests\TestCase;

/**
 * @internal
 */
class CliBootstrapTest extends TestCase
{
    protected function servicesFromTestConfig(): array
    {
        return $this->getMockConfig()->get('services', []);
    }

    public function testFilterDropsEveryHttpCoupledService()
    {
        $filtered = CliKernel::filterServices($this->servicesFromTestConfig());

        foreach ([
            'Filegator\Services\Router\Router',
            'Filegator\Services\Session\SessionStorageInterface',
            'Filegator\Services\Security\Security',
            'Filegator\Services\View\ViewInterface',
        ] as $excluded) {
            $this->assertArrayNotHasKey($excluded, $filtered, $excluded.' must not boot in CLI');
        }
    }

    /**
     * Router::init does not merely register routes, it DISPATCHES immediately.
     * With no ?r= parameter the uri resolves to '/', so booting it under cron
     * would run a web controller and render the app HTML into the cron log.
     */
    public function testRouterIsExcludedBecauseItDispatchesOnInit()
    {
        $this->assertArrayNotHasKey(
            'Filegator\Services\Router\Router',
            CliKernel::filterServices($this->servicesFromTestConfig())
        );
    }

    /**
     * Nothing in the report path needs a user, so the CLI structurally cannot
     * act as one. This is also load-bearing rather than merely principled:
     * JsonFile's constructor type-hints the session service, which is excluded,
     * so including auth would fail to resolve.
     */
    public function testAuthIsExcluded()
    {
        $this->assertArrayNotHasKey(
            'Filegator\Services\Auth\AuthInterface',
            CliKernel::filterServices($this->servicesFromTestConfig())
        );
    }

    public function testFilterKeepsWhatTheJobNeeds()
    {
        $filtered = CliKernel::filterServices($this->servicesFromTestConfig());

        foreach ([
            'Filegator\Services\Logger\LoggerInterface',
            'Filegator\Services\Mailer\MailerInterface',
            'Filegator\Services\Audit\AuditMailer',
            'Filegator\Services\Audit\AuditLog',
            'Filegator\Services\Audit\ReportStore',
            'Filegator\Services\Audit\MonthlyReport',
        ] as $needed) {
            $this->assertArrayHasKey($needed, $filtered, $needed.' is required by the job');
        }
    }

    /**
     * Services are constructed and init()'d in array order, and a later
     * service's constructor may type-hint an earlier alias.
     */
    public function testFilterPreservesRelativeOrder()
    {
        $original = array_keys($this->servicesFromTestConfig());
        $filtered = array_keys(CliKernel::filterServices($this->servicesFromTestConfig()));

        $expected = array_values(array_filter($original, function ($key) use ($filtered) {
            return in_array($key, $filtered, true);
        }));

        $this->assertSame($expected, $filtered);
    }

    public function testContainerBootsAndResolvesTheJob()
    {
        $config = $this->getMockConfig();
        $raw = ['timezone' => 'UTC', 'services' => $config->get('services', [])];

        $container = CliKernel::boot($raw);
        $report = $container->get(MonthlyReport::class);

        $this->assertInstanceOf(MonthlyReport::class, $report);
        $this->assertTrue($report->isConfigured());
    }

    /**
     * Passing only the services array would drop top-level settings, and Config
     * silently defaults `timezone` to UTC — which would make the cron's month
     * boundaries disagree with the web app's on any non-UTC deployment.
     */
    public function testTopLevelConfigSurvivesTheFilter()
    {
        $raw = [
            'timezone' => 'America/Chicago',
            'services' => $this->getMockConfig()->get('services', []),
        ];

        $container = CliKernel::boot($raw);

        $this->assertSame('America/Chicago', $container->get(\Filegator\Config\Config::class)->get('timezone'));
    }

    /**
     * Shelling out catches what unit tests structurally cannot: a wrong
     * relative path, a syntax error in the entry point, or a missing define.
     */
    public function testEntryPointRunsAndReportsUsage()
    {
        $root = dirname(__DIR__, 3);

        exec('php '.escapeshellarg($root.'/bin/filegator').' help 2>&1', $output, $code);
        $joined = implode("\n", $output);

        // Exit 2 is the "no configuration.php" path, which is legitimate in a
        // checkout that has never run the web app; either way it must not fatal.
        $this->assertContains($code, [0, 2], 'entry point should not fatal: '.$joined);
        $this->assertStringNotContainsString('Fatal error', $joined);
        $this->assertStringNotContainsString('Parse error', $joined);
    }

    /**
     * Structural guard against the hazard that shaped this whole entry point.
     *
     * App's boot handler catches every Throwable and ends in a bare `die;`,
     * which exits with status ZERO — under cron that makes any boot failure
     * look like a successful run, forever. The CLI must therefore never
     * construct App, and must guard its own boot. Asserting on the source is
     * blunt, but it is the only thing that fails if someone later "simplifies"
     * this back to `new App(...)`, since the regression would be silent by
     * construction.
     */
    public function testEntryPointDoesNotBootThroughApp()
    {
        $root = dirname(__DIR__, 3);
        $entry = file_get_contents($root.'/bin/filegator');
        $kernel = file_get_contents($root.'/backend/Cli/CliKernel.php');

        $this->assertStringNotContainsString('new App(', $entry);
        $this->assertStringNotContainsString('new App(', $kernel);
        // ...and it must still catch its own boot failures and exit non-zero.
        $this->assertStringContainsString('catch (\Throwable', $entry);
        $this->assertStringContainsString('exit(1)', $entry);
    }

    /**
     * bin/ sits outside the docroot, but placement is a deployment convention.
     * The SAPI guard is the control, and it must be the first thing that runs.
     */
    public function testEntryPointRefusesNonCliSapi()
    {
        $entry = file_get_contents(dirname(__DIR__, 3).'/bin/filegator');

        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $entry);
        $this->assertFileExists(dirname(__DIR__, 3).'/bin/.htaccess');
        $this->assertStringContainsString(
            'deny from all',
            file_get_contents(dirname(__DIR__, 3).'/bin/.htaccess')
        );
    }
}
