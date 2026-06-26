<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Logger\Adapters;

use Filegator\Services\Logger\LoggerInterface;
use Filegator\Services\Service;
use Monolog\ErrorHandler;
use Monolog\Logger;

class MonoLogger implements Service, LoggerInterface
{
    protected $logger;

    /**
     * Monolog's ErrorHandler installs process-global error/fatal handlers that
     * chain to the previously-registered one (callPrevious = true). The app is
     * bootstrapped once per process in production, but anything that re-boots it
     * — a long-lived worker, or the test suite booting a fresh app per case —
     * would stack an ever-deeper handler chain on each init(). Under Xdebug that
     * recursive chain eventually exceeds the function-nesting limit and every
     * subsequent error turns into a failure. The handlers only need to be
     * installed once per process, so guard the registration.
     */
    private static $globalHandlersRegistered = false;

    public function init(array $config = [])
    {
        $this->logger = new Logger('default');

        foreach ($config['monolog_handlers'] as $handler) {
            $this->logger->pushHandler($handler());
        }

        if (! self::$globalHandlersRegistered) {
            $handler = new ErrorHandler($this->logger);
            $handler->registerErrorHandler([], true);
            $handler->registerFatalHandler();
            self::$globalHandlersRegistered = true;
        }
    }

    public function log(string $message, int $level = Logger::INFO)
    {
        $this->logger->log($level, $message);
    }
}
