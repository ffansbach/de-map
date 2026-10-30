<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Web;

use PHPUnit\Framework\TestCase;

/**
 * The real log_to_db.php and lib/log.php, run as a script. Only the mysqli class is replaced by one that
 * writes the queries it receives into a file, so no database is needed.
 */
final class LogToDbTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->directory = sys_get_temp_dir() . '/ffk_logtodb_' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/lib', 0777, true);
        mkdir($this->directory . '/cache');
        copy($root . '/web/log_to_db.php', $this->directory . '/log_to_db.php');
        copy($root . '/web/lib/log.php', $this->directory . '/lib/log.php');
        $this->writeConfig(true);
        $this->writeRouters(1500);

        file_put_contents($this->directory . '/stub.php', <<<'PHP'
            <?php
            // stands in for the mysql extension and for the web server's request
            if (getenv('STUB_TOKEN') !== false) {
                $_REQUEST['token'] = getenv('STUB_TOKEN');
            }
            class mysqli
            {
                public $connect_errno = 0;
                public $connect_error = '';

                public function __construct($host, $user, $pass, $db)
                {
                    $this->connect_errno = (int) getenv('STUB_CONNECT_ERRNO');
                    $this->connect_error = $this->connect_errno ? 'Connection refused' : '';
                    file_put_contents(getenv('STUB_QUERIES'), 'connect ' . $host . ' ' . $user . ' ' . $db . "\n", FILE_APPEND);
                }

                public function query($query)
                {
                    file_put_contents(getenv('STUB_QUERIES'), $query . "\n", FILE_APPEND);

                    return true;
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['lib', 'cache', ''] as $sub) {
            foreach (glob($this->directory . ($sub !== '' ? '/' . $sub : '') . '/*') ?: [] as $file) {
                is_file($file) ? unlink($file) : null;
            }
        }

        @rmdir($this->directory . '/lib');
        @rmdir($this->directory . '/cache');
        @rmdir($this->directory);
    }

    private function writeConfig(bool $withDatabase): void
    {
        file_put_contents($this->directory . '/config.php', '<?php'
            . "\n\$setDataLogPointToken = 'secret-token';"
            . "\n\$dbAccess = " . ($withDatabase ? "array('host' => 'db.test', 'user' => 'u', 'pass' => 'p', 'db' => 'stats');" : 'array();'));
    }

    private function writeRouters(int $count): void
    {
        file_put_contents($this->directory . '/cache/result_routers.json', (string) json_encode(array_fill(0, $count, ['id' => 'x'])));
    }

    /**
     * @return array{output: string, queries: list<string>}
     */
    private function runScript(?string $token, int $connectErrno = 0): array
    {
        $environment = ['PATH' => (string) getenv('PATH'), 'STUB_QUERIES' => $this->directory . '/queries.log', 'STUB_CONNECT_ERRNO' => (string) $connectErrno];

        if ($token !== null) {
            $environment['STUB_TOKEN'] = $token;
        }

        $process = proc_open(
            [PHP_BINARY, '-d', 'error_log=' . $this->directory . '/error.log', '-d', 'disable_classes=mysqli', '-d', 'auto_prepend_file=' . $this->directory . '/stub.php', $this->directory . '/log_to_db.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->directory,
            $environment,
        );

        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $queries = is_file($this->directory . '/queries.log') ? file($this->directory . '/queries.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        return ['output' => $output, 'queries' => $queries === false ? [] : $queries];
    }

    public function testWritesTheNumberOfNodesWithTheRightToken(): void
    {
        $result = $this->runScript('secret-token');

        $this->assertSame('', $result['output']);
        $this->assertSame(['connect db.test u stats', 'INSERT INTO log SET `count` = 1500'], $result['queries']);
    }

    public function testDoesNothingWithAWrongOrMissingToken(): void
    {
        foreach (['wrong', null] as $token) {
            $result = $this->runScript($token);

            $this->assertSame('', $result['output']);
            $this->assertSame([], $result['queries'], (string) $token);
        }
    }

    public function testTheTokenIsComparedAsAStringNotAsANumber(): void
    {
        file_put_contents($this->directory . '/config.php', "<?php\n\$setDataLogPointToken = '10';\n\$dbAccess = array('host' => 'db.test', 'user' => 'u', 'pass' => 'p', 'db' => 'stats');");

        $this->assertSame([], $this->runScript('1e1')['queries'], '"1e1" == "10" in PHP, but it is not the token');
        $this->assertSame([], $this->runScript('10.0')['queries']);
        $this->assertNotSame([], $this->runScript('10')['queries']);
    }

    public function testAnEmptyConfiguredTokenLetsNobodyIn(): void
    {
        file_put_contents($this->directory . '/config.php', "<?php\n\$setDataLogPointToken = '';\n\$dbAccess = array('host' => 'db.test', 'user' => 'u', 'pass' => 'p', 'db' => 'stats');");

        $this->assertSame([], $this->runScript('')['queries']);
    }

    public function testWritesNothingWhenThereAreNoNodes(): void
    {
        $this->writeRouters(0);

        $result = $this->runScript('secret-token');

        $this->assertSame(['connect db.test u stats'], $result['queries']);
    }

    public function testSaysSoWhenThereIsNoDatabaseConfigured(): void
    {
        $this->writeConfig(false);

        $result = $this->runScript('secret-token');

        $this->assertSame('no db config found', $result['output']);
        $this->assertSame([], $result['queries']);
    }

    public function testReportsAConnectionErrorWithoutDetails(): void
    {
        $result = $this->runScript('secret-token', 2002);

        $this->assertStringContainsString('database not available', $result['output']);
        $this->assertStringNotContainsString('Connection refused', $result['output'], 'the details go to the error log, not to the caller');
        $this->assertStringNotContainsString('db.test', $result['output']);
        $this->assertStringContainsString('(2002) Connection refused', (string) file_get_contents($this->directory . '/error.log'));
        $this->assertSame(['connect db.test u stats'], $result['queries']);
    }

    public function testNeedsNeitherComposerNorANamespace(): void
    {
        // the web host has no vendor/ directory; the script must run from just these two files and config.php
        $this->assertFileDoesNotExist($this->directory . '/vendor/autoload.php');
        $this->assertSame(['connect db.test u stats', 'INSERT INTO log SET `count` = 1500'], $this->runScript('secret-token')['queries']);
    }
}
