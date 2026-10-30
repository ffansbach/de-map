<?php

declare(strict_types=1);

namespace FreifunkKarte\Command;

use Closure;
use FreifunkKarte\Community\MetaCommunityResolver;
use FreifunkKarte\Config\IntegrationConfig;
use FreifunkKarte\Config\RunConfig;
use FreifunkKarte\Http\ParallelFetcher;
use FreifunkKarte\Log\LoggerFactory;
use FreifunkKarte\Output\ResultWriter;
use FreifunkKarte\Parser\SourceParser;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\ParseRun;
use FreifunkKarte\Publish\DeliveryFactory;
use FreifunkKarte\Publish\ResultFiles;
use FreifunkKarte\Run\DriftDetector;
use FreifunkKarte\Run\DriftEvent;
use FreifunkKarte\Run\RecordedRun;
use FreifunkKarte\Run\RunRecorder;
use FreifunkKarte\Run\RunRepository;
use FreifunkKarte\Source\ApiDocumentStore;
use FreifunkKarte\Source\Directory;
use FreifunkKarte\Source\FixedCommunities;
use FreifunkKarte\Source\LastGoodStore;
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
            ->addOption('config-dir', null, InputOption::VALUE_REQUIRED, 'directory with skipped-communities.json, insecure-hosts.json, metacommunities.json, source-assignments.json')
            ->addOption('directory-url', null, InputOption::VALUE_REQUIRED, 'the community directory to read', Directory::URL)
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'run a single community (directory key)')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED, 'more communities to skip, comma separated')
            ->addOption('no-fixed', null, InputOption::VALUE_NONE, 'leave out the communities of fixedCommunities.php')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'parallel requests', '12')
            ->addOption('min-communities', null, InputOption::VALUE_REQUIRED, 'refuse to write fewer listed communities than this', '10')
            ->addOption('min-nodes', null, InputOption::VALUE_REQUIRED, 'refuse to write fewer nodes than this', '1000')
            ->addOption('force', null, InputOption::VALUE_NONE, 'write even if the result looks implausibly small')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'SQLite file with the history of runs', 'var/parser.sqlite')
            ->addOption('log-file', null, InputOption::VALUE_REQUIRED, 'JSON log (a file per day is kept for two weeks)', 'var/log/parser.log')
            ->addOption('keep-days', null, InputOption::VALUE_REQUIRED, 'forget runs older than this many days; 0 keeps the whole history', '0')
            ->addOption('keep-detail-days', null, InputOption::VALUE_REQUIRED, 'drop the requests and messages of runs older than this many days and keep their summaries; 0 keeps all detail', '30')
            ->addOption('no-history', null, InputOption::VALUE_NONE, 'do not record the run, compare it, log it or report it anywhere')
            ->addOption('integrations', null, InputOption::VALUE_REQUIRED, 'JSON file with the healthchecks.io / InfluxDB settings (default: config/integrations.local.json if it exists)')
            ->addOption('upload', null, InputOption::VALUE_NONE, 'deliver the result files to the web server afterwards')
            ->addOption('upload-config', null, InputOption::VALUE_REQUIRED, 'JSON file with the upload settings (default: config/upload.local.json)');
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
        $configOption = $input->getOption('config-dir');
        $configDir = is_string($configOption) ? $configOption : $projectDir . '/config';
        $config = RunConfig::load($configDir);
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
        $delivery = null;

        if ($input->getOption('upload')) {
            $uploadConfig = $input->getOption('upload-config');

            try {
                $delivery = DeliveryFactory::create($client, $configDir, is_string($uploadConfig) ? $uploadConfig : null);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            if (!$delivery->hasPublishers()) {
                $io->error('--upload, but no upload is configured (see config/upload.example.json)');

                return Command::FAILURE;
            }
        }

        $run = new ParseRun(
            new ParallelFetcher($client, max(1, (int) $input->getOption('concurrency')), insecureHosts: $config->insecureHosts, retryWithoutVerification: false),
            new SourceParser(),
            new SourceCache($cacheDir . '/sources.json'),
            MetaCommunityResolver::fromFile($config->metaCommunitiesFile),
            null,
            (string) $input->getOption('directory-url'),
            lastGood: new LastGoodStore($cacheDir . '/last-good'),
            apiDocuments: new ApiDocumentStore($cacheDir . '/last-good-api'),
            assignments: $config->assignments,
        );

        $recorded = null;

        if (!$input->getOption('no-history')) {
            $repository = RunRepository::open((string) $input->getOption('db'));
            $recorder = new RunRecorder(
                $repository,
                new DriftDetector(),
                LoggerFactory::create((string) $input->getOption('log-file')),
                $this->integrations($input, $configDir, $projectDir)->listeners($client),
            );
            $recorded = new RecordedRun($recorder, $repository, $recorder->begin($out));
        }

        try {
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

            $listed = count($result->listedCommunities());
            $refused = !$input->getOption('force') && ($listed < (int) $input->getOption('min-communities') || $result->nodes->count() < (int) $input->getOption('min-nodes'));
            $paths = $refused ? [] : (new ResultWriter())->write($result, $out);
        } catch (\Throwable $e) {
            if ($recorded !== null) {
                $recorded->recorder->fail($recorded->id, $e);
            }

            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $outcomes = array_count_values(array_map(static fn (CommunityReport $c): string => $c->outcome, $result->communities));
        $warnings = array_sum(array_map(static fn (CommunityReport $c): int => count($c->warnings), $result->communities));

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

        $deliveryError = null;
        $deliveryWarnings = [];

        if ($delivery !== null && !$refused) {
            try {
                $report = $delivery->deliver(ResultFiles::fromDirectory($out));
                $deliveryError = $report->error;
                $deliveryWarnings = $report->warnings;

                foreach ($report->results as $delivered) {
                    $io->writeln(sprintf('%s %s: %s', $delivered->ok ? 'delivered' : 'NOT delivered', $delivered->publisher, $delivered->message));
                }
            } catch (\Throwable $e) {
                $deliveryError = $e->getMessage();
            }
        }

        if ($recorded !== null) {
            $summary = $recorded->recorder->complete($recorded->id, $result, $refused, $deliveryError, $deliveryWarnings);
            $this->printEvents($io, $summary->events);
            $keepDays = (int) $input->getOption('keep-days');

            if ($keepDays > 0) {
                $recorded->repository->prune($keepDays, $summary->finishedAt);
            }

            $keepDetailDays = (int) $input->getOption('keep-detail-days');

            if ($keepDetailDays > 0) {
                $compacted = $recorded->repository->compact($keepDetailDays, $summary->finishedAt);

                if ($compacted > 0 && $io->isVerbose()) {
                    $io->writeln(sprintf('history: dropped the detail of %d runs older than %d days', $compacted, $keepDetailDays));
                }
            }
        }

        if ($refused) {
            $io->error(sprintf('Result looks implausible (%d communities, %d nodes): nothing written. Use --force to write anyway.', $listed, $result->nodes->count()));

            return Command::FAILURE;
        }

        if ($deliveryError !== null) {
            $io->error('The result files were written but not delivered: ' . $deliveryError);

            return Command::FAILURE;
        }

        foreach ($paths as $path) {
            $io->writeln($path);
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<DriftEvent> $events
     */
    private function printEvents(SymfonyStyle $io, array $events): void
    {
        $important = array_values(array_filter($events, static fn (DriftEvent $e): bool => $e->severity !== DriftEvent::INFO));

        if ($important === []) {
            return;
        }

        $io->section('To look at');

        foreach ($important as $event) {
            $io->writeln(sprintf('<%1$s>%2$s</%1$s> %3$s', $event->severity === DriftEvent::ALERT ? 'error' : 'comment', strtoupper($event->severity), $event->message));
        }
    }

    private function integrations(InputInterface $input, string $configDir, string $projectDir): IntegrationConfig
    {
        $json = $input->getOption('integrations');

        if (is_string($json)) {
            return IntegrationConfig::fromJsonFile($json);
        }

        $default = ($configDir !== '' ? $configDir : $projectDir . '/config') . '/integrations.local.json';

        return is_file($default) ? IntegrationConfig::fromJsonFile($default) : new IntegrationConfig();
    }
}
