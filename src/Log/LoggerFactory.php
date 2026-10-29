<?php

declare(strict_types=1);

namespace FreifunkKarte\Log;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class LoggerFactory
{
    /**
     * One JSON object per line, a new file every day, two weeks kept.
     */
    public static function create(?string $file, int $keepFiles = 14): LoggerInterface
    {
        if ($file === null) {
            return new NullLogger();
        }

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create ' . $directory);
        }

        $handler = new RotatingFileHandler($file, $keepFiles, Level::Info);
        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true));

        return new Logger('parser', [$handler]);
    }
}
