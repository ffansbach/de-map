<?php

declare(strict_types=1);

namespace FreifunkKarte\Command;

use Closure;
use FreifunkKarte\Community\MetaCommunityResolver;
use FreifunkKarte\Config\RunConfig;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Output\LegacyResultWriter;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\ParseRun;
use FreifunkKarte\Source\Directory;
use FreifunkKarte\Source\FixedCommunities;
use FreifunkKarte\Source\SourceCache;
use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'run',
    description: 'Fetch all communities, parse their nodes and write the result files',
)]
final class RunCommand extends Command
{
    /**
     * @param Closure|null $httpHandler replaces the network (tests)
     * @param string|null $projectDir where config/ and fixedCommunities.php are
     */
    public function __construct(
        private readonly ?Closure $httpHandler = null,
        private readonly ?string $projectDir = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'directory for the result_*.json files (required, so a test run never overwrites live files)')
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'where the parser keeps what it learned about maps', 'var/cache')
            ->addOption('config-dir', null, InputOption::VALUE_REQUIRED, 'directory with skipped-communities.json, insecure-hosts.json, metacommunities.json')
            ->addOption('directory-url', null, InputOption::VALUE_REQUIRED, 'the community directory to read', Directory::URL)
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'run a single community (directory key)')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED, 'more communities to skip, comma separated')
            ->addOption('no-fixed', null, InputOption::VALUE_NONE, 'leave out the communities of fixedCommunities.php')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'parallel requests', '12')
            ->addOption('min-communities', null, InputOption::VALUE_REQUIRED, 'refuse to write fewer listed communities than this', '10')
            ->addOption('min-nodes', null, InputOption::VALUE_REQUIRED, 'refuse to write fewer nodes than this', '1000')
            ->addOption('force', null, InputOption::VALUE_NONE, 'write even if the result looks implausibly small');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $out = $input->getOption('out');

        if (!is_string($out) || $out === '') {
            $io->error('--out=DIRECTORY is required');

            return Command::INVALID;
        }

        $projectDir = $this->projectDir ?? dirname(__DIR__, 2);
        $configDir = $input->getOption('config-dir');
        $config = RunConfig::load(is_string($configDir) ? $configDir : $projectDir . '/config');
        $skip = array_keys($config->skippedCommunities);

        if (is_string($input->getOption('skip'))) {
            array_push($skip, ...array_filter(array_map('trim', explode(',', (string) $input->getOption('skip')))));
        }

        $cacheDir = (string) $input->getOption('cache-dir');

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            $io->error('Cannot create ' . $cacheDir);

            return Command::FAILURE;
        }

        $client = new Client($this->httpHandler !== null ? ['handler' => $this->httpHandler] : []);
        $run = new ParseRun(
            new ParallelFetcher($client, max(1, (int) $input->getOption('concurrency')), insecureHosts: $config->insecureHosts, retryWithoutVerification: false),
            new SourceParser(),
            new SourceCache($cacheDir . '/sources.json'),
            MetaCommunityResolver::fromFile($config->metaCommunitiesFile),
            null,
            (string) $input->getOption('directory-url'),
        );

        $only = $input->getOption('only');
        $result = $run->run(
            $input->getOption('no-fixed') ? [] : FixedCommunities::load($projectDir . '/fixedCommunities.php'),
            $skip,
            is_string($only) ? $only : null,
            static function (string $message) use ($io): void {
                if ($io->isVerbose()) {
                    $io->writeln('<comment>' . $message . '</comment>');
                }
            },
        );

        $outcomes = array_count_values(array_map(static fn (CommunityReport $c): string => $c->outcome, $result->communities));
        $warnings = array_sum(array_map(static fn (CommunityReport $c): int => count($c->warnings), $result->communities));
        $listed = count($result->listedCommunities());

        $io->definitionList(
            ['nodes' => $result->nodes->count()],
            ['communities listed' => $listed],
            ['with nodes' => $outcomes[CommunityReport::OK] ?? 0],
            ['maps without data' => $outcomes[CommunityReport::NO_DATA] ?? 0],
            ['API file not available' => $outcomes[CommunityReport::API_UNAVAILABLE] ?? 0],
            ['without nodeMaps' => $outcomes[CommunityReport::NO_NODEMAPS] ?? 0],
            ['skipped' => $outcomes[CommunityReport::SKIPPED] ?? 0],
            ['source warnings' => $warnings],
            ['requests' => $result->requests],
            ['time' => sprintf('%.1f s', $result->seconds)],
            ['peak memory' => sprintf('%.0f MB', memory_get_peak_usage(true) / 1048576)],
        );

        if ($io->isVerbose()) {
            foreach ($result->communities as $community) {
                if ($community->outcome === CommunityReport::NO_DATA) {
                    $io->writeln(sprintf('no data: %s (%s)', $community->key, implode(', ', $community->declaredTypes)));
                }
            }
        }

        if (!$input->getOption('force') && ($listed < (int) $input->getOption('min-communities') || $result->nodes->count() < (int) $input->getOption('min-nodes'))) {
            $io->error(sprintf('Result looks implausible (%d communities, %d nodes): nothing written. Use --force to write anyway.', $listed, $result->nodes->count()));

            return Command::FAILURE;
        }

        foreach ((new LegacyResultWriter())->write($result, $out) as $path) {
            $io->writeln($path);
        }

        return Command::SUCCESS;
    }
}
