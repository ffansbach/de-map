<?php

/**
 * Front controller of the admin UI. Serve this directory (see PRD.md, section "Admin UI"), or run
 * `bin/parser admin:serve` for a local look. Everything is read-only.
 */

declare(strict_types=1);

use FreifunkKarte\Admin\AdminApp;
use FreifunkKarte\Admin\AdminConfig;
use FreifunkKarte\Admin\ResponseEmitter;
use FreifunkKarte\Admin\Routes;
use FreifunkKarte\Log\LoggerFactory;
use FreifunkKarte\Run\RunRepository;
use GuzzleHttp\Psr7\ServerRequest;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

try {
    $configFile = getenv('FFKARTE_ADMIN_CONFIG') ?: $root . '/config/admin.local.json';
    $config = AdminConfig::fromFile($configFile);
    $dbFile = str_starts_with($config->dbFile, '/') ? $config->dbFile : $root . '/' . $config->dbFile;
    $logger = LoggerFactory::create($root . '/var/log/admin.log');

    try {
        $repository = RunRepository::openReadOnly($dbFile);
    } catch (RuntimeException $e) {
        // the detail names a path on the server: for the log, not for the client
        $logger->warning('history not available', ['error' => $e->getMessage()]);
        ResponseEmitter::emit(AdminApp::unavailable('The history is not available yet. It is created by the first run of the parser.'));

        return;
    }

    ResponseEmitter::emit(Routes::app($repository, $config, $logger)->handle(ServerRequest::fromGlobals()));
} catch (Throwable $e) {
    // never show details to the client; they are in the web server's error log
    error_log('admin UI: ' . $e::class . ': ' . $e->getMessage());
    ResponseEmitter::emit(AdminApp::unavailable('The admin UI is not set up correctly. See the web server log.'));
}
