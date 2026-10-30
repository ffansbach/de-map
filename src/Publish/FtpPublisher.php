<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

use Closure;
use Throwable;

/**
 * The old way, kept as a fallback and for running next to the new one: upload every file under a
 * staging name, check its size, and only then rename all of them into place, in binary mode.
 */
final class FtpPublisher implements Publisher
{
    /**
     * @param Closure(): FtpConnection $connect opens a connection (not yet logged in)
     * @param string $remoteDirectory where the web part reads its result files
     */
    public function __construct(
        private readonly Closure $connect,
        private readonly string $host,
        private readonly string $user,
        private readonly string $password,
        private readonly string $remoteDirectory = '/',
    ) {
    }

    public function name(): string
    {
        return 'ftp ' . $this->host;
    }

    public function publish(ResultFiles $files): PublishResult
    {
        $started = microtime(true);
        $connection = null;
        $staged = [];

        try {
            $connection = ($this->connect)();
            $connection->login($this->user, $this->password);
            $directory = rtrim($this->remoteDirectory, '/');

            foreach ($files->files as $name => $content) {
                $staging = $directory . '/' . $name . '.staging';
                $connection->upload($staging, $content);
                $staged[$name] = $staging;
                $size = $connection->size($staging);

                if ($size !== -1 && $size !== strlen($content)) {
                    throw new \RuntimeException(sprintf('%s arrived with %d of %d bytes', $name, $size, strlen($content)));
                }
            }

            foreach ($staged as $name => $staging) {
                $final = $directory . '/' . $name;

                // some servers refuse to rename over an existing file: then it has to go first
                $placed = $connection->rename($staging, $final);

                if (!$placed) {
                    $connection->delete($final);
                    $placed = $connection->rename($staging, $final);
                }

                if (!$placed) {
                    throw new \RuntimeException('cannot put ' . $name . ' in place');
                }

                unset($staged[$name]);
            }
        } catch (Throwable $e) {
            foreach ($staged as $staging) {
                $connection?->delete($staging);
            }

            $connection?->close();

            // the password is not in the message: only what went wrong
            return new PublishResult($this->name(), false, $e->getMessage(), microtime(true) - $started);
        }

        $connection->close();

        return new PublishResult($this->name(), true, sprintf('%d files, %d KB sent', count($files->files), (int) round($files->size() / 1024)), microtime(true) - $started, $files->size());
    }
}
