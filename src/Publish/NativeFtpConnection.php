<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

use FTP\Connection;
use RuntimeException;

final class NativeFtpConnection implements FtpConnection
{
    private function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param bool $ssl explicit FTP over TLS (the password does not travel in the clear)
     */
    public static function connect(string $host, int $port = 21, bool $ssl = true, int $timeout = 30): self
    {
        $connection = $ssl ? @ftp_ssl_connect($host, $port, $timeout) : @ftp_connect($host, $port, $timeout);

        if ($connection === false) {
            throw new RuntimeException(sprintf('cannot connect to %s:%d%s', $host, $port, $ssl ? ' with TLS' : ''));
        }

        return new self($connection);
    }

    public function login(string $user, string $password): void
    {
        if (!@ftp_login($this->connection, $user, $password)) {
            throw new RuntimeException('the server refused the login');
        }

        ftp_pasv($this->connection, true);
    }

    public function upload(string $remotePath, string $content): void
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('no temporary stream');
        }

        try {
            fwrite($stream, $content);
            rewind($stream);

            // binary: JSON must arrive byte for byte
            if (!@ftp_fput($this->connection, $remotePath, $stream, FTP_BINARY)) {
                throw new RuntimeException('the transfer of ' . basename($remotePath) . ' failed');
            }
        } finally {
            fclose($stream);
        }
    }

    public function size(string $remotePath): int
    {
        return ftp_size($this->connection, $remotePath);
    }

    public function rename(string $from, string $to): bool
    {
        return @ftp_rename($this->connection, $from, $to);
    }

    public function delete(string $path): bool
    {
        return @ftp_delete($this->connection, $path);
    }

    public function close(): void
    {
        @ftp_close($this->connection);
    }
}
