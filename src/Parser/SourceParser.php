<?php

declare(strict_types=1);

namespace FreifunkKarte\Parser;

use DateTimeImmutable;
use FreifunkKarte\Audit\Format;
use FreifunkKarte\Audit\FormatDetector;
use JsonException;

/**
 * Turns the raw response of a node source into nodes. The parser is chosen by what the
 * response contains, never by what the community declared as map type.
 */
final class SourceParser
{
    /** @var array<string, NodeParser> */
    private array $parsers = [];

    public function __construct(
        private readonly FormatDetector $detector = new FormatDetector(),
        private readonly NodelistSchemaChecker $schemaChecker = new NodelistSchemaChecker(),
        ?OfflineRule $offlineRule = null,
    ) {
        $offlineRule ??= new OfflineRule();

        foreach ([new NodeInfoParser($offlineRule), new MeshviewerFlatParser($offlineRule), new NodelistParser($offlineRule)] as $parser) {
            $this->parsers[$parser->format()->value] = $parser;
        }
    }

    public function parse(string $body, DateTimeImmutable $now): ParseResult
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return ParseResult::unusable(Format::Empty, 'empty response');
        }

        if ($trimmed[0] === '<') {
            return ParseResult::unusable(Format::Html, 'response is a web page, not data');
        }

        try {
            $data = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ParseResult::unusable(Format::InvalidJson, 'response is not valid JSON: ' . $e->getMessage());
        }

        $detection = $this->detector->detectData($data);
        $parser = $this->parsers[$detection->format->value] ?? null;

        if ($detection->format === Format::FfmapConfig) {
            return new ParseResult(
                Format::FfmapConfig,
                warnings: [$this->unsupportedReason(Format::FfmapConfig)],
                parsed: false,
                dataPaths: $detection->dataPaths,
            );
        }

        if ($parser === null || !is_array($data)) {
            return ParseResult::unusable($detection->format, $this->unsupportedReason($detection->format));
        }

        $result = $parser->parse($data, $now);

        if ($detection->format === Format::Nodelist) {
            $result = $result->withWarnings($this->schemaChecker->check($trimmed));
        }

        return $result;
    }

    private function unsupportedReason(Format $format): string
    {
        return match ($format) {
            Format::LegacyGeo => 'old ffmap format with geo[] is no longer supported',
            Format::FfmapConfig => 'response is a map config.json, not a node list',
            Format::NodesEmpty => 'node list is empty',
            Format::NodesUnknown => 'nodes are in an unknown format',
            default => 'response is JSON but contains no node list',
        };
    }
}
