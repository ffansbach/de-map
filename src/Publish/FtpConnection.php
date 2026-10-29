<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

/**
 * The few FTP operations the publisher needs; the real one wraps PHP's ftp functions, tests use a fake.
 */
interface FtpConnection
{
    /**
     * @throws \RuntimeException if the server refuses the login
     */
    public function login(string $user, string $password): void;

    /**
     * @throws \RuntimeException if the transfer fails
     */
    public function upload(string $remotePath, string $content): void;

    /**
     * @return int the size in bytes, -1 if the server does not say
     */
    public function size(string $remotePath): int;

    public function rename(string $from, string $to): bool;

    public function delete(string $path): bool;

    public function close(): void;
}
