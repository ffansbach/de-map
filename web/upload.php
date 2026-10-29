<?php

/**
 * Receives the result files of the parser (result_routers.json, result_communities.json, ...) and
 * installs them for the map. Runs on the web server of the map; needs PHP 7.2 or newer and nothing else.
 *
 * Deploy: copy this file to the web server, create upload.config.php next to it (see
 * upload.config.example.php), and let `bin/parser run --upload` send to it.
 *
 * The request (protocol v1):
 *   POST, body = JSON {"version":1,"files":{"result_routers.json":"<file content>", ...}}, optionally gzip
 *   X-FFK-Timestamp       seconds since 1970, at most 5 minutes off
 *   X-FFK-Nonce           random string, used once
 *   X-FFK-Content-SHA256  hex SHA-256 of the body as sent
 *   X-FFK-Signature       "v1=" + hex HMAC-SHA256(secret, "FFK-UPLOAD-V1\nPOST\n<path>\n<timestamp>\n<nonce>\n<sha256>")
 *
 * The signature is checked before the body is read, so that nobody without the secret can make this
 * script read megabytes. Whatever is wrong with a request that is not signed correctly, the answer is
 * the same short "unauthorized"; the reason goes to the error log.
 */

final class FfkUploadReceiver
{
    const FILES = array('result_routers.json', 'result_communities.json', 'result_statistics.json', 'result_metacommunities.json');
    const REQUIRED = array('result_routers.json', 'result_communities.json');
    const MIN_SECRET_LENGTH = 32;

    /** @var array<string, mixed> */
    private $config;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $logger;

    /**
     * @param array<string, mixed> $config
     * @param callable|null $clock returns the current time as a Unix timestamp
     * @param callable|null $logger receives a line for the error log
     */
    public function __construct(array $config, $clock = null, $logger = null)
    {
        $this->config = array_merge(array(
            'secret' => '',
            'target' => '',
            'stateDir' => sys_get_temp_dir() . '/ffk-upload',
            'maxBytes' => 20000000,
            'maxDecodedBytes' => 64000000,
            'timestampWindow' => 300,
            'requireHttps' => true,
            'trustForwardedProto' => false,
            'expectedPath' => null,
            'minCommunities' => 10,
            'minRouters' => 1000,
            'maxShrink' => 0.5,
        ), $config);
        $this->clock = $clock !== null ? $clock : 'time';
        $this->logger = $logger !== null ? $logger : 'error_log';
    }

    /**
     * @param array<string, mixed> $server like $_SERVER
     * @param callable $readBody function (int $maxBytes): string - called only for an authenticated request
     * @return array{0: int, 1: array<string, mixed>} HTTP status and the answer
     */
    public function handle(array $server, callable $readBody): array
    {
        try {
            return $this->process($server, $readBody);
        } catch (\Throwable $e) {
            $this->log('error: ' . get_class($e) . ': ' . $e->getMessage());

            return array(500, array('ok' => false, 'error' => 'server error'));
        }
    }

    /**
     * @param array<string, mixed> $server
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function process(array $server, callable $readBody): array
    {
        if (strlen((string) $this->config['secret']) < self::MIN_SECRET_LENGTH || !is_dir((string) $this->config['target'])) {
            $this->log('not configured: secret shorter than ' . self::MIN_SECRET_LENGTH . ' characters or target is no directory');

            return array(500, array('ok' => false, 'error' => 'not configured'));
        }

        if (isset($server['REQUEST_METHOD']) && $server['REQUEST_METHOD'] !== 'POST') {
            return array(405, array('ok' => false, 'error' => 'POST only'));
        }

        if ($this->config['requireHttps'] && !$this->isHttps($server)) {
            return array(400, array('ok' => false, 'error' => 'HTTPS only'));
        }

        $length = isset($server['CONTENT_LENGTH']) ? (int) $server['CONTENT_LENGTH'] : -1;

        if ($length > (int) $this->config['maxBytes']) {
            return array(413, array('ok' => false, 'error' => 'too large'));
        }

        $now = (int) call_user_func($this->clock);
        $nonce = $this->header($server, 'X-FFK-Nonce');
        $claimedHash = strtolower($this->header($server, 'X-FFK-Content-SHA256'));
        $timestamp = $this->header($server, 'X-FFK-Timestamp');
        $signature = $this->header($server, 'X-FFK-Signature');

        if ($nonce === '' || $timestamp === '' || $signature === '' || !preg_match('/^[0-9a-f]{64}$/', $claimedHash)
            || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) || !ctype_digit($timestamp)) {
            return $this->unauthorized('missing or malformed headers');
        }

        if (abs($now - (int) $timestamp) > (int) $this->config['timestampWindow']) {
            return $this->unauthorized('timestamp outside the window (clocks differ by ' . ($now - (int) $timestamp) . ' s)');
        }

        $path = $this->config['expectedPath'] !== null ? (string) $this->config['expectedPath'] : (string) parse_url(isset($server['REQUEST_URI']) ? $server['REQUEST_URI'] : '/', PHP_URL_PATH);
        $expected = 'v1=' . hash_hmac('sha256', implode("\n", array('FFK-UPLOAD-V1', 'POST', $path, $timestamp, $nonce, $claimedHash)), (string) $this->config['secret']);

        if (!hash_equals($expected, $signature)) {
            usleep(200000);

            return $this->unauthorized('wrong signature (path "' . $path . '")');
        }

        if (!$this->claimNonce($nonce, $now)) {
            return $this->unauthorized('nonce used before');
        }

        // from here on the sender is known: read the body
        $body = (string) call_user_func($readBody, (int) $this->config['maxBytes']);

        if (strlen($body) > (int) $this->config['maxBytes']) {
            return array(413, array('ok' => false, 'error' => 'too large'));
        }

        if (!hash_equals($claimedHash, hash('sha256', $body))) {
            return array(400, array('ok' => false, 'error' => 'body does not match its hash'));
        }

        $decoded = $this->decode($body, $this->header($server, 'Content-Encoding'));

        if (!is_array($decoded)) {
            return array($decoded === 'too large' ? 413 : 422, array('ok' => false, 'error' => $decoded));
        }

        $checked = $this->validate($decoded);

        if (is_string($checked)) {
            $this->log('rejected: ' . $checked);

            return array(422, array('ok' => false, 'error' => $checked));
        }

        $this->install($checked['files']);

        return array(200, array(
            'ok' => true,
            'routers' => $checked['routers'],
            'communities' => $checked['communities'],
            'files' => array_map('strlen', $checked['files']),
        ));
    }

    /**
     * @return array<mixed>|string the envelope, or a message if it can not be used
     */
    private function decode(string $body, string $encoding)
    {
        if (strtolower($encoding) === 'gzip') {
            if (!function_exists('gzdecode')) {
                return 'gzip is not supported here';
            }

            // the limit is the protection against a small body that unpacks to gigabytes
            $plain = @gzdecode($body, (int) $this->config['maxDecodedBytes'] + 1);

            if ($plain === false) {
                return 'body is not valid gzip';
            }

            if (strlen($plain) > (int) $this->config['maxDecodedBytes']) {
                return 'too large';
            }

            $body = $plain;
        }

        $data = json_decode($body, true, 8);

        return is_array($data) ? $data : 'body is not valid JSON';
    }

    /**
     * @param array<mixed> $envelope
     * @return array{files: array<string, string>, routers: int, communities: int}|string
     */
    private function validate(array $envelope)
    {
        if (!isset($envelope['version']) || $envelope['version'] !== 1) {
            return 'unknown protocol version';
        }

        if (!isset($envelope['files']) || !is_array($envelope['files'])) {
            return 'no files';
        }

        $files = array();
        $counts = array();

        foreach ($envelope['files'] as $name => $content) {
            if (!in_array($name, self::FILES, true)) {
                return 'unknown file ' . substr((string) $name, 0, 50);
            }

            if (!is_string($content)) {
                return $name . ' is no text';
            }

            $data = json_decode($content, true, 8);

            if (!is_array($data)) {
                return $name . ' is not valid JSON';
            }

            $problem = $this->shape($name, $data);

            if ($problem !== null) {
                return $name . ': ' . $problem;
            }

            $files[$name] = $content;
            $counts[$name] = count($data);
        }

        foreach (self::REQUIRED as $name) {
            if (!isset($files[$name])) {
                return $name . ' is missing';
            }
        }

        $routers = isset($counts['result_routers.json']) ? $counts['result_routers.json'] : 0;
        $communities = isset($counts['result_communities.json']) ? $counts['result_communities.json'] : 0;

        if ($routers < (int) $this->config['minRouters'] || $communities < (int) $this->config['minCommunities']) {
            return sprintf('implausibly small (%d nodes, %d communities)', $routers, $communities);
        }

        $before = $this->currentRouters();

        if ($before > 0 && (float) $this->config['maxShrink'] > 0 && $routers < $before * (float) $this->config['maxShrink']) {
            return sprintf('%d nodes where there are %d now: refused', $routers, $before);
        }

        return array('files' => $files, 'routers' => $routers, 'communities' => $communities);
    }

    /**
     * @param array<mixed> $data
     * @return string|null what is wrong with the file, or null
     */
    private function shape(string $name, array $data)
    {
        if ($name === 'result_routers.json') {
            foreach ($data as $router) {
                if (!is_array($router) || !isset($router['id'], $router['lat'], $router['long'], $router['name'], $router['community'], $router['status'])) {
                    return 'an entry is not a node';
                }
            }
        } elseif ($name === 'result_communities.json') {
            foreach ($data as $community) {
                if (!is_array($community) || !isset($community['name'], $community['url'])) {
                    return 'an entry is not a community';
                }
            }
        } elseif ($name === 'result_statistics.json') {
            if (!isset($data['timestamp'], $data['errorCommunities']) || !is_array($data['errorCommunities'])) {
                return 'not the statistics';
            }
        }

        return null;
    }

    private function currentRouters(): int
    {
        $file = rtrim((string) $this->config['target'], '/') . '/result_routers.json';

        if (!is_file($file)) {
            return 0;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? count($data) : 0;
    }

    /**
     * Writes every file next to its place and then renames it over the old one.
     *
     * @param array<string, string> $files
     */
    private function install(array $files): void
    {
        $target = rtrim((string) $this->config['target'], '/');
        $staged = array();

        foreach ($files as $name => $content) {
            $staging = $target . '/' . $name . '.' . bin2hex(random_bytes(4)) . '.staging';

            if (file_put_contents($staging, $content, LOCK_EX) === false) {
                $this->discard($staged);
                @unlink($staging);

                throw new \RuntimeException('cannot write ' . $staging);
            }

            $staged[$name] = $staging;
        }

        foreach ($staged as $name => $staging) {
            if (!@rename($staging, $target . '/' . $name)) {
                $this->discard($staged);

                throw new \RuntimeException('cannot replace ' . $name);
            }

            unset($staged[$name]);
        }
    }

    /**
     * @param array<string, string> $staged
     */
    private function discard(array $staged): void
    {
        foreach ($staged as $staging) {
            @unlink($staging);
        }
    }

    /**
     * A nonce can be used once: the file is created exclusively, a second try finds it.
     */
    private function claimNonce(string $nonce, int $now): bool
    {
        $directory = (string) $this->config['stateDir'];

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('cannot create the state directory');
        }

        $known = glob($directory . '/nonce-*');

        foreach ($known === false ? array() : $known as $old) {
            if ($now - (int) @filemtime($old) > 2 * (int) $this->config['timestampWindow']) {
                @unlink($old);
            }
        }

        $file = $directory . '/nonce-' . hash('sha256', $nonce);
        $handle = @fopen($file, 'x');

        if ($handle === false) {
            return false;
        }

        fclose($handle);
        @touch($file, $now);

        return true;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function isHttps(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }

        return $this->config['trustForwardedProto'] && isset($server['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $server['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    /**
     * @param array<string, mixed> $server
     */
    private function header(array $server, string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($server[$key]) ? trim((string) $server[$key]) : '';
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function unauthorized(string $reason): array
    {
        $this->log('unauthorized: ' . $reason);

        return array(401, array('ok' => false, 'error' => 'unauthorized'));
    }

    private function log(string $line): void
    {
        call_user_func($this->logger, 'ffk-upload: ' . $line);
    }
}

// Run only when this file is the script that was requested (not when it is included by a test).
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $configFile = getenv('FFK_UPLOAD_CONFIG') ?: __DIR__ . '/upload.config.php';
    $config = is_file($configFile) ? require $configFile : array();
    $receiver = new FfkUploadReceiver(is_array($config) ? $config : array());

    list($status, $answer) = $receiver->handle($_SERVER, function ($maxBytes) {
        $input = fopen('php://input', 'rb');

        return $input === false ? '' : (string) stream_get_contents($input, $maxBytes + 1);
    });

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($answer);
}
