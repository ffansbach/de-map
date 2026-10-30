<?php

declare(strict_types=1);

namespace FreifunkKarte\Tests\Audit;

use FreifunkKarte\Audit\Format;
use FreifunkKarte\Audit\FormatDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures in tests/fixtures/sources are anonymised excerpts of real responses
 * (built by `bin/parser fixtures:build`). Later steps characterise the parsers with them.
 */
final class SourceFixturesTest extends TestCase
{
    /**
     * @return array<string, array{string, Format, int, int}>
     */
    public static function fixtureProvider(): array
    {
        return [
            'meshviewer nodes.json (nodeinfo)' => ['nodeinfo.json', Format::NodeInfo, 7, 5],
            'meshviewer.json (flat)' => ['meshviewer_flat.json', Format::MeshviewerFlat, 8, 6],
            'nodelist' => ['nodelist.json', Format::Nodelist, 8, 6],
        ];
    }

    #[DataProvider('fixtureProvider')]
    public function testFixtureIsDetected(string $file, Format $format, int $nodes, int $located): void
    {
        $body = file_get_contents(__DIR__ . '/../fixtures/sources/' . $file);
        $this->assertIsString($body);

        $result = (new FormatDetector())->detect($body);

        $this->assertSame($format, $result->format);
        $this->assertSame($nodes, $result->nodes);
        $this->assertSame($located, $result->located);
    }

    #[DataProvider('fixtureProvider')]
    public function testFixtureContainsNoPersonalData(string $file, Format $format, int $nodes, int $located): void
    {
        $body = (string) file_get_contents(__DIR__ . '/../fixtures/sources/' . $file);

        $this->assertDoesNotMatchRegularExpression('/@|https?:\/\/|([0-9a-f]{2}:){5}[0-9a-f]{2}|"owner"|"contact"/i', $body);
    }
}
