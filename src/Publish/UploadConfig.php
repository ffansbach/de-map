<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

use GuzzleHttp\ClientInterface;
use RuntimeException;

/**
 * Where the result files go. Secrets live outside the repository: in config/upload.local.json
 * (see upload.example.json) or, for the FTP upload of the legacy parser, in its config.php.
 */
final readonly class UploadConfig
{
    /**
     * @param list<array<string, mixed>> $publishers
     * @param string|null $statsPingUrl called after a successful delivery (log_to_db.php of the web part)
     */
    public function __construct(
        public array $publishers = [],
        public ?string $statsPingUrl = null,
    ) {
    }

    public static function fromJsonFile(string $file): self
    {
        $data = json_decode((string) @file_get_contents($file), true);

        if (!is_array($data)) {
            throw new RuntimeException($file . ' is missing or not valid JSON');
        }

        $publishers = [];

        foreach (is_array($data['publishers'] ?? null) ? $data['publishers'] : [] as $index => $publisher) {
            if (!is_array($publisher) || !in_array($publisher['type'] ?? null, ['https', 'ftp'], true)) {
                throw new RuntimeException(sprintf('%s: publisher #%d needs a type of "https" or "ftp"', $file, $index + 1));
            }

            $publishers[] = $publisher;
        }

        $ping = $data['statsPingUrl'] ?? null;

        return new self($publishers, is_string($ping) && $ping !== '' ? $ping : null);
    }

    /**
     * Reads $serverUpload (FTP), $setDataLogPointToken and $environment from the legacy config.php.
     * Like the legacy parser, the statistics are only pinged on the live environment.
     */
    public static function fromLegacyConfig(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException($file . ' does not exist');
        }

        ob_start();

        try {
            $variables = (static function (string $configFile): array {
                require $configFile;
                unset($configFile);

                return get_defined_vars();
            })($file);
        } finally {
            ob_end_clean();
        }

        $ftp = $variables['serverUpload'] ?? null;
        $publishers = [];

        if (is_array($ftp) && is_string($ftp['host'] ?? null) && $ftp['host'] !== '') {
            $publishers[] = [
                'type' => 'ftp',
                'host' => $ftp['host'],
                'user' => (string) ($ftp['user'] ?? ''),
                'password' => (string) ($ftp['password'] ?? ''),
                'directory' => (string) ($ftp['target'] ?? '/'),
                'required' => true,
            ];
        }

        $token = $variables['setDataLogPointToken'] ?? null;
        $live = ($variables['environment'] ?? null) === 'live';

        return new self(
            $publishers,
            $live && is_string($token) && $token !== '' ? 'https://www.freifunk-karte.de/log_to_db.php?token=' . rawurlencode($token) : null,
        );
    }

    /**
     * @return list<array{Publisher, bool}> the publisher and whether the delivery fails without it
     */
    public function build(ClientInterface $client): array
    {
        $built = [];

        foreach ($this->publishers as $index => $settings) {
            $required = ($settings['required'] ?? true) !== false;

            $built[] = [match ($settings['type']) {
                'https' => new HttpsPublisher($client, self::text($settings, 'url', $index), self::text($settings, 'secret', $index)),
                default => $this->ftp($settings, $index),
            }, $required];
        }

        return $built;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function ftp(array $settings, int $index): FtpPublisher
    {
        $host = self::text($settings, 'host', $index);
        $port = is_int($settings['port'] ?? null) ? $settings['port'] : 21;
        $ssl = ($settings['ssl'] ?? true) !== false;

        return new FtpPublisher(
            static fn (): FtpConnection => NativeFtpConnection::connect($host, $port, $ssl),
            $host,
            self::text($settings, 'user', $index),
            self::text($settings, 'password', $index),
            is_string($settings['directory'] ?? null) ? $settings['directory'] : '/',
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function text(array $settings, string $key, int $index): string
    {
        $value = $settings[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Upload publisher #%d: "%s" is missing', $index + 1, $key));
        }

        return $value;
    }
}
