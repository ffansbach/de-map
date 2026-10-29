<?php

declare(strict_types=1);

namespace FreifunkKarte\Run;

use DateTimeImmutable;
use FreifunkKarte\Pipeline\CommunityReport;
use FreifunkKarte\Pipeline\RunResult;
use PDO;
use RuntimeException;

/**
 * The history of runs in a SQLite file: what every run did to every community and every URL,
 * and what looked odd about it. Written by the cron run, read by the admin UI.
 */
final class RunRepository
{
    public const RUNNING = 'running';
    public const OK = 'ok';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';

    private const SCHEMA_VERSION = 1;

    private function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
    }

    public static function open(string $file): self
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }

        $pdo = new PDO('sqlite:' . $file);
        // the admin UI reads while a run writes
        $pdo->exec('PRAGMA journal_mode = WAL');

        return new self($pdo);
    }

    public static function inMemory(): self
    {
        return new self(new PDO('sqlite::memory:'));
    }

    public function start(DateTimeImmutable $at, string $outDir): int
    {
        $this->pdo->prepare('INSERT INTO runs (started_at, status, out_dir) VALUES (?, ?, ?)')
            ->execute([$at->format('c'), self::RUNNING, $outDir]);

        return (int) $this->pdo->lastInsertId();
    }

    public function finish(int $runId, RunResult $result, string $status, DateTimeImmutable $finishedAt): void
    {
        $this->pdo->beginTransaction();

        try {
            $outcomes = array_count_values(array_map(static fn (CommunityReport $c): string => $c->outcome, $result->communities));

            $this->pdo->prepare('UPDATE runs SET finished_at = ?, status = ?, seconds = ?, requests = ?, nodes = ?, communities = ?, with_nodes = ?, without_data = ? WHERE id = ?')
                ->execute([
                    $finishedAt->format('c'), $status, $result->seconds, $result->requests, $result->nodes->count(),
                    count($result->listedCommunities()), $outcomes[CommunityReport::OK] ?? 0, $outcomes[CommunityReport::NO_DATA] ?? 0, $runId,
                ]);

            $this->storeCommunities($runId, $result);
            $this->storeFetches($runId, $result);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function fail(int $runId, string $error, DateTimeImmutable $finishedAt): void
    {
        $this->pdo->prepare('UPDATE runs SET finished_at = ?, status = ?, error = ? WHERE id = ?')
            ->execute([$finishedAt->format('c'), self::FAILED, mb_substr($error, 0, 2000), $runId]);
    }

    /**
     * @param list<DriftEvent> $events
     */
    public function addEvents(int $runId, array $events, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare('INSERT INTO events (run_id, kind, severity, subject, message, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');

        foreach ($events as $event) {
            $statement->execute([$runId, $event->kind, $event->severity, $event->subject, $event->message, json_encode($event->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $at->format('c')]);
        }
    }

    /**
     * The newest finished run before the given one that produced a result (not failed).
     */
    public function previousRun(int $runId): ?int
    {
        $statement = $this->pdo->prepare("SELECT id FROM runs WHERE id < ? AND status = 'ok' ORDER BY id DESC LIMIT 1");
        $statement->execute([$runId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @return array<string, StoredCommunity> by community key
     */
    public function communities(int $runId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM community_results WHERE run_id = ?');
        $statement->execute([$runId]);
        $communities = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $communities[(string) $row['key']] = new StoredCommunity(
                (string) $row['key'],
                (string) $row['name'],
                (string) $row['outcome'],
                $row['format'] === null ? null : (string) $row['format'],
                (bool) $row['listed'],
                (int) $row['found'],
                (int) $row['added'],
                (int) $row['duplicates'],
                $row['stale_since'] === null ? null : (string) $row['stale_since'],
                self::strings($row['declared_types']),
                self::strings($row['source_urls']),
                self::strings($row['warnings']),
            );
        }

        return $communities;
    }

    /**
     * Every technicalType any earlier run has seen, for "never seen before" detection.
     *
     * @return list<string>
     */
    public function knownTypes(int $beforeRunId): array
    {
        $statement = $this->pdo->prepare('SELECT declared_types FROM community_results WHERE run_id < ?');
        $statement->execute([$beforeRunId]);
        $types = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach (self::strings($json) as $type) {
                $types[$type] = true;
            }
        }

        return array_map('strval', array_keys($types));
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function runs(int $limit = 30): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM runs ORDER BY id DESC LIMIT ?');
        $statement->execute([$limit]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function run(int $runId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM runs WHERE id = ?');
        $statement->execute([$runId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>> every URL requested in a run, slowest first
     */
    public function fetches(int $runId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM fetches WHERE run_id = ? ORDER BY seconds DESC, url');
        $statement->execute([$runId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array<string, mixed>> events of one run, alerts first
     */
    public function events(int $runId): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM events WHERE run_id = ? ORDER BY CASE severity WHEN 'alert' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, id");
        $statement->execute([$runId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function recentEvents(int $limit = 100, ?string $minimumSeverity = null): array
    {
        $levels = ['info' => ['info', 'warning', 'alert'], 'warning' => ['warning', 'alert'], 'alert' => ['alert']];
        $allowed = $levels[$minimumSeverity ?? 'info'] ?? $levels['info'];
        $statement = $this->pdo->prepare('SELECT * FROM events WHERE severity IN (' . implode(',', array_fill(0, count($allowed), '?')) . ') ORDER BY id DESC LIMIT ?');
        $statement->execute([...$allowed, $limit]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * How one community did in the last runs.
     *
     * @return list<array<string, mixed>> newest first
     */
    public function communityHistory(string $key, int $limit = 30): array
    {
        $statement = $this->pdo->prepare('SELECT r.id AS run_id, r.started_at, c.outcome, c.format, c.found, c.added, c.duplicates, c.stale_since FROM community_results c JOIN runs r ON r.id = c.run_id WHERE c.key = ? ORDER BY r.id DESC LIMIT ?');
        $statement->execute([$key, $limit]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Forgets runs older than the given number of days.
     */
    public function prune(int $keepDays, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare('DELETE FROM runs WHERE started_at < ?');
        $statement->execute([$now->modify(sprintf('-%d days', $keepDays))->format('c')]);

        return $statement->rowCount();
    }

    private function storeCommunities(int $runId, RunResult $result): void
    {
        $statement = $this->pdo->prepare('INSERT INTO community_results (run_id, key, name, origin, outcome, listed, format, via_cache, stale_since, found, added, duplicates, without_location, dropped, invalid, declared_types, source_urls, warnings, messages, attempts, meta, api_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($result->communities as $c) {
            $statement->execute([
                $runId, $c->key, $c->name, $c->origin, $c->outcome, (int) $c->listed, $c->format, (int) $c->viaCache, $c->staleSince,
                $c->found, $c->added, $c->duplicates, $c->withoutLocation, $c->dropped, $c->invalid,
                self::json($c->declaredTypes), self::json($c->sourceUrls), self::json($c->warnings), self::json($c->messages),
                self::json(array_map(static fn ($a): array => ['url' => $a->url, 'status' => $a->status, 'error' => $a->error, 'format' => $a->format, 'nodes' => $a->nodes], $c->attempts)),
                $c->meta, $c->apiUrl,
            ]);
        }
    }

    private function storeFetches(int $runId, RunResult $result): void
    {
        $statement = $this->pdo->prepare('INSERT OR REPLACE INTO fetches (run_id, url, status, error, bytes, seconds, tls_invalid, format, nodes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($result->fetches as $f) {
            $statement->execute([$runId, $f->url, $f->status, $f->error, $f->bytes, round($f->seconds, 3), (int) $f->tlsInvalid, $f->format, $f->nodes]);
        }
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? array_values(array_filter($data, is_string(...))) : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function migrate(): void
    {
        $statement = $this->pdo->query('PRAGMA user_version');
        $version = $statement === false ? 0 : (int) $statement->fetchColumn();

        if ($version >= self::SCHEMA_VERSION) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at TEXT NOT NULL,
                finished_at TEXT,
                status TEXT NOT NULL,
                seconds REAL,
                requests INTEGER,
                nodes INTEGER,
                communities INTEGER,
                with_nodes INTEGER,
                without_data INTEGER,
                out_dir TEXT,
                error TEXT
            );
            CREATE TABLE community_results (
                run_id INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
                key TEXT NOT NULL,
                name TEXT NOT NULL,
                origin TEXT NOT NULL,
                outcome TEXT NOT NULL,
                listed INTEGER NOT NULL,
                format TEXT,
                via_cache INTEGER NOT NULL DEFAULT 0,
                stale_since TEXT,
                found INTEGER NOT NULL,
                added INTEGER NOT NULL,
                duplicates INTEGER NOT NULL,
                without_location INTEGER NOT NULL,
                dropped INTEGER NOT NULL,
                invalid INTEGER NOT NULL,
                declared_types TEXT NOT NULL,
                source_urls TEXT NOT NULL,
                warnings TEXT NOT NULL,
                messages TEXT NOT NULL,
                attempts TEXT NOT NULL,
                meta TEXT,
                api_url TEXT,
                PRIMARY KEY (run_id, key)
            );
            CREATE INDEX idx_community_results_key ON community_results(key, run_id);
            CREATE TABLE fetches (
                run_id INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
                url TEXT NOT NULL,
                status INTEGER,
                error TEXT,
                bytes INTEGER NOT NULL,
                seconds REAL NOT NULL,
                tls_invalid INTEGER NOT NULL,
                format TEXT,
                nodes INTEGER NOT NULL,
                PRIMARY KEY (run_id, url)
            );
            CREATE TABLE events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id INTEGER NOT NULL REFERENCES runs(id) ON DELETE CASCADE,
                kind TEXT NOT NULL,
                severity TEXT NOT NULL,
                subject TEXT NOT NULL,
                message TEXT NOT NULL,
                details TEXT NOT NULL,
                created_at TEXT NOT NULL
            );
            CREATE INDEX idx_events_run ON events(run_id);
            SQL);
        $this->pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }
}
