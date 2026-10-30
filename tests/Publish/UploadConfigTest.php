<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Publish;

use FreifunkKarte\Publish\FtpPublisher;
use FreifunkKarte\Publish\HttpsPublisher;
use FreifunkKarte\Publish\UploadConfig;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class UploadConfigTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ffk_upcfg_' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    private function write(string $name, string $content): string
    {
        file_put_contents($this->directory . '/' . $name, $content);

        return $this->directory . '/' . $name;
    }

    public function testTheExampleFileIsValidAndBuildsBothPublishers(): void
    {
        $example = dirname(__DIR__, 2) . '/config/upload.example.json';
        $config = UploadConfig::fromJsonFile($this->write('up.json', str_replace('make one with: bin/parser upload:secret', str_repeat('s', 40), (string) file_get_contents($example))));

        $built = $config->build(new Client());

        $this->assertInstanceOf(HttpsPublisher::class, $built[0][0]);
        $this->assertFalse($built[0][1], 'the example starts with https next to ftp: ftp decides');
        $this->assertInstanceOf(FtpPublisher::class, $built[1][0]);
        $this->assertTrue($built[1][1]);
        $this->assertStringStartsWith('https://www.freifunk-karte.de/log_to_db.php', (string) $config->statsPingUrl);
    }

    public function testPublishersAreRequiredUnlessSaidOtherwise(): void
    {
        $config = UploadConfig::fromJsonFile($this->write('up.json', (string) json_encode(['publishers' => [['type' => 'https', 'url' => 'https://x.example/u.php', 'secret' => str_repeat('k', 32)]]])));

        $this->assertTrue($config->build(new Client())[0][1]);
    }

    public function testAnUnknownTypeIsRefused(): void
    {
        $this->expectExceptionMessage('publisher #1 needs a type');

        UploadConfig::fromJsonFile($this->write('up.json', '{"publishers":[{"type":"scp"}]}'));
    }

    public function testAMissingSettingNamesWhatIsMissing(): void
    {
        $config = UploadConfig::fromJsonFile($this->write('up.json', '{"publishers":[{"type":"https","url":"https://x.example/u.php"}]}'));

        $this->expectExceptionMessage('Upload publisher #1: "secret" is missing');

        $config->build(new Client());
    }

    public function testBrokenJsonIsRefused(): void
    {
        $this->expectExceptionMessage('not valid JSON');

        UploadConfig::fromJsonFile($this->write('up.json', '{'));
    }
}
