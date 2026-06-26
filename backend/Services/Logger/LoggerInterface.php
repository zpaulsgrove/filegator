<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Logger;

interface LoggerInterface
{
    /**
     * Default log level. The numeric value matches Monolog / RFC 5424 "INFO";
     * the only adapter (MonoLogger) maps these straight to Monolog levels, and
     * one caller already passes Monolog\Logger::WARNING here. Declaring the
     * default makes the single-argument calls used throughout the codebase
     * (e.g. $logger->log('message')) valid against the contract instead of
     * relying on the adapter happening to relax the signature.
     */
    public const INFO = 200;

    public function log(string $message, int $level = self::INFO);
}
