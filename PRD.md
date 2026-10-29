# PRD: freifunk-karte backend modernisation

Living document. It is extended at the end of every step. Last updated: 2026-10-29 (step 2).

## 1. Purpose

freifunk-karte (https://www.freifunk-karte.de/) shows the nodes of all German Freifunk communities on one Leaflet map. The data is not owned by the project: it is aggregated from the node lists that the communities publish themselves.

This PRD covers the **backend/parser**. The goal is a clean, secure, tested, observable and fast PHP 8.3+ code base. The public map frontend is not part of it for now.

## 2. Users

| User | Needs |
|------|-------|
| Map visitors | Fast map with current nodes. They only see `result_*.json`. |
| Operator (Tino) | Reliable cron runs, quick answers to "which community broke, since when, why". |
| Community admins | Their nodes show up. Clear instructions on what format to publish. |

## 3. Current state (as of step 1)

### Deployment
- **Web** runs on www.freifunk-karte.de (index.php, `data.php` in read mode, `fetch.php`, `js/`).
- **Parser** runs on a Proxmox LXC. A cron job calls `data.php?<reparse-key>&processonly&upload`, which parses everything, writes `cache/result_*.json` and uploads them via FTP.
- The result JSON is over 3 MB (it used to be bigger).
- Git remote is a self-hosted Gitea (`gitea.fritz.box`). CI is not set up (decision: none for now).

### Data flow
1. `directory.json` from the freifunk/directory.api.freifunk.net repo gives the community list (name → API file URL).
2. Communities with a `nodelist`-type nodeMap are parsed first (`lib/parser/NodeList.php`, schema-validated).
3. All other communities: API file → `nodeMaps` → `ffmap`/`meshviewer`/`hopglass` parsed by `getFromFfmap`.
4. Hard-coded extra communities from `fixedCommunities.php`.
5. Nodes are deduplicated by md5 of name + id + lat + long. Offline nodes older than 3 days are dropped.
6. Output: `result_communities.json`, `result_routers.json`, `result_metacommunities.json`, `result_statistics.json` in `cache/`.

### Pain points
- One 1100-line class does fetching, URL discovery, parsing, caching and logging, with no seams for testing.
- Everything is sequential. 17 fixed + many directory communities with 5 s connect / 20 s total timeouts each.
- Almost no tests (23, only cache handler and debug collector).
- Per-run diagnostics are overwritten each run, so there is no history.
- Mixed web/CLI entry point (`data.php`), secrets via query string.

## 4. Requirements

### Must
- **R1** Output of `result_*.json` stays compatible with `js/meta_map.js` until the dedicated slimming step.
- **R2** PHP 8.3+, strict types, static analysis (PHPStan) and PSR-12 for all new code.
- **R3** Fetching and parsing are parallel and deterministic (same input → same output order).
- **R4** Parsers are pure (string in, nodes out) and covered by tests based on real sample files.
- **R5** Parser types that are no longer used are identified with data and removed.
- **R6** Every run is recorded (runs, per-community results, per-fetch results) and can be inspected in an internal UI on the LXC.
- **R7** Secure transport of results to the webhost (replace FTP by a signed HTTPS upload API).
- **R8** TLS verification on by default, response size limits, bounded redirects.

### Should
- **R9** Structured logging (JSON lines) and metrics (InfluxDB v2, healthchecks.io) as run listeners.
- **R10** "Which communities broke since the last run" visible at a glance.

### Non-goals (for now)
- Frontend redesign (`js/`, Leaflet) apart from what the later output slimming needs.
- Running the parser on the webhost.
- Writing new parsers for formats nobody uses.

## 5. Roadmap

| # | Step | Status |
|---|------|--------|
| 1 | PRD + tooling baseline (PHP 8.3, PHPUnit 11, PHPStan, CS-Fixer) | done |
| 2 | Source audit tool + sample fixtures; decide which parsers to delete | done |
| 3 | Characterisation tests on the old code (golden outputs) | open |
| 4 | New models + pure parsers, full unit coverage | open |
| 5 | HTTP fetcher (parallel), source resolver, pipeline; side-by-side run on LXC | open |
| 6 | Run tracking (SQLite) + structured logging | open |
| 7 | Internal admin UI on the LXC | open |
| 8 | Secure upload API (HMAC) | open |
| 9 | Cut-over and cleanup of legacy code | open |
| 10 | Output slimming (own PRD section later) | open |

Working rules: one step at a time, report after each, separate focused commits.

## 6. Decision log

| Date | Decision | Why |
|------|----------|-----|
| 2026-10-29 | Stay on PHP (8.3+), no rewrite in another language | Existing code base and operator know-how, web side stays PHP. |
| 2026-10-29 | Admin UI lives on the LXC, internal only | Run data stays private, no auth surface on the public host. |
| 2026-10-29 | Keep output byte-compatible first, slim later | Allows a safe side-by-side comparison with the old parser. |
| 2026-10-29 | Replace FTP by a signed HTTPS upload API | FTP is plaintext and its rename logic is broken (see findings). |
| 2026-10-29 | No CI for now; `composer check` locally | Gitea runner not set up. |
| 2026-10-29 | PHPStan at level 5 with a baseline for `lib/`; CS-Fixer only on new code | Avoids churning code that will be deleted, while new code starts clean. |
| 2026-10-29 | Dropped `phpseclib` (unused) and `lukaswhite/directory` (abandoned, deprecations on 8.5) | Fewer dependencies; phpseclib alone had 5 advisories. |
| 2026-10-29 | Choose the parser by the **content** of a response (`FormatDetector`), not by the declared `technicalType` | The audit shows the declared type is unreliable (section 8). |
| 2026-10-29 | Parsers to keep: nodelist, nodeinfo (ffmap/meshviewer/hopglass), flat meshviewer.json. To delete: netmon/openwifimap/olsr-dot leftovers and the `geo[]` "old style" ffmap branch | 0 working sources for the former, none of 551 probed sources delivers `geo[]` (section 8). |
| 2026-10-29 | TLS verification stays on in the new fetcher, with a per-host exception list | Only 1 of ~1150 probed URLs has an invalid certificate (section 8). |
| 2026-10-29 | Source audit and fixtures are part of the CLI (`bin/parser sources:audit`, `fixtures:build`), not one-off scripts | Re-runnable; fixtures are reproducible and anonymised by whitelist. |

## 7. Findings

### Fixed in step 1
- `composer audit` reported 18 advisories: `guzzlehttp/guzzle` 7.8.1 (9), `guzzlehttp/psr7` (4), `phpseclib` (5, unused). Now 0.
- Test provider key `message` did not match parameter `$messages` (silently ignored by PHPUnit 9, an error in 11).
- The cache test used `../cache_test` relative to the CWD and threw if it existed.

### Open bugs in legacy code (not fixed yet; they disappear with the rewrite and get regression tests)
| Where | Problem |
|-------|---------|
| `NodeListParser::getFromFfmap` ~l.718 | Result of `getNodesFromCachedFfmapUrl()` is discarded, so the 7-day working-URL cache is never used. All candidate URLs are probed each run. |
| `NodeListParser::getFromFfmap` ~l.789 | `array_merge()` result discarded: with several HopGlass `dataPath`s only the first source counts. |
| `upload_cache.php` | `ftp_delete($fileName)` lacks `$conn_id`; `ftp_rawlist` output is compared to bare file names (never matches); `result_metacommunities.json` is never uploaded. |
| `data.php` | `$e->getMessage` without `()`. `new mysqli` inside `namespace ffmap` resolves to `ffmap\mysqli` (fatal when `$dbAccess` is set). |
| `lib/log.php` | Type hint `mysqli` resolves to `ffmap\mysqli`, so passing a real `\mysqli` is a TypeError. |
| `lib/HealthCheck.php` | `ping()` throws `ErrorException` which resolves to `ffmap\ErrorException` (nonexistent), so an empty identifier causes a fatal instead of the intended exception. `$healtcheckIdentifier` is missing in `config.php_example.php`. |
| `lib/InfluxLog.php` | Still imports InfluxDB v1 classes (`InfluxDB\Client`, `Database`, `Point`) that are no longer installed, plus `http\Exception\InvalidArgumentException` (pecl_http). |
| `lib/CurlHelper.php` | TLS verification disabled globally; `CURLOPT_IPRESOLVE` set with `CURL_VERSION_IPV6` (wrong constant); no response size limit; recursive manual redirect handling. |
| `lib/NodeListParser.php` | `getParsed()` discards per-run community diagnostics after one overwritten statistics file, so there is no history. |

PHPStan baseline: `phpstan-baseline.neon` (22 entries). It must shrink to zero as `lib/` is replaced.

## 8. Source audit (step 2)

`bin/parser sources:audit --json=report.json` (read-only, about 45 s at 8 parallel requests). Snapshot of 2026-10-29: 337 communities in the directory, 551 node sources (512 from the directory + 39 from `fixedCommunities.php`), about 1150 URLs probed including discovery.

| Declared type | Entries | Working | Real formats of the working ones |
|---------------|--------:|--------:|----------------------------------|
| nodelist | 246 | 230 | nodelist 228, nodeinfo 1, meshviewer_flat 1 |
| meshviewer | 170 | 72 | nodeinfo 64, meshviewer_flat 8 |
| ffmap | 56 | 27 | meshviewer_flat 15, nodeinfo 12 |
| hopglass | 33 | 21 | nodeinfo 21 |
| *(no technicalType)* | 41 | 0 | only HTML or 404 |
| openwifimap | 4 | 0 | HTML, 404, unreachable |
| olsr-dot | 1 | 0 | invalid JSON |
| netmon | 0 | – | not declared anywhere any more |

Working sources by real format: nodelist 228, nodeinfo 98, meshviewer_flat 24, **legacy `geo[]`: 0**. 268 of 337 communities have at least one working source (16 API files not loadable, 24 API files without nodeMaps).

### Conclusions
1. **Parsers to keep:** nodelist, nodeinfo (this is what ffmap, meshviewer and hopglass maps deliver), flat `meshviewer.json`. Three parsers, selected by content.
2. **Parsers to delete:** the netmon/openwifimap/olsr-dot leftovers (message branches and cache handling in `NodeListParser`) and the "old style" `geo[]` branch of `getFromFfmap`: not one source delivers it.
3. **`technicalType` is unreliable:** `meshviewer` maps mostly deliver nodeinfo (64 of 72), `nodelist` maps sometimes deliver something else (2), `ffmap` maps mostly deliver the flat format (15 of 27). The old `"getFrom" . $parser` dispatch on the declared type must go.
4. **Discovery is still needed:** 75 working sources (66 of them `meshviewer`) are only found via `config.json`/`dataPath` or the usual `nodes.json` locations, not at the declared URL.
5. **Priority matters:** 60 communities have a working nodelist *and* another working source, so the current rule "nodelist wins" decides which nodes appear.
6. **TLS:** only `map.freifunk-rheinbach.de` has an invalid certificate. Disabling verification globally in `CurlHelper` is not necessary.
7. **Skip list (`data.php`) is partly stale:** `lueneburg` works again. `ansbach`, `heilsbronn` and `neuendettelsau` have unreachable directory entries but working fixed entries. `emskirchen`, `neustadt-aisch` and `recklinghausen` are not in the directory any more. The list should become data (per-community, with reason and date) and be reviewed in step 5.
8. **Gaps for step 5 (discovery):** 16 `meshviewer` communities point at a viewer page but no data is found at any of the known locations (berlin, leipzig, siegburg, troisdorf, rheinbach, …). About 57 requests ran into the connect timeout; it should be a per-run budget, not a per-request wait.

### Fixtures
`tests/fixtures/sources/{nodeinfo,meshviewer_flat,nodelist}.json`: 7-8 nodes each (online/offline, with/without location), anonymised by key whitelist, hostnames and ids replaced, coordinates rounded to 2 decimals. A test guards against contact data, MACs and URLs. Rebuild with `bin/parser sources:audit --raw-dir=DIR` and `bin/parser fixtures:build --from=DIR` (never commit the raw directory).

Still to create in step 3 (characterisation): a trimmed `directory.json`, a few community API files and hand-written edge cases (client count shapes, keyed ffmap v1 `nodes`, stale offline nodes).

## 9. Tooling

```bash
composer install
composer check      # php-cs-fixer (dry run) + phpstan + phpunit
composer test | stan | cs | cs-fix
bin/parser sources:audit --json=report.json   # read-only, needs network
bin/parser fixtures:build --from=RAWDIR       # see section 8
```

Dev machine: PHP 8.5, Composer 2.10. PHPUnit 11.5, PHPStan 2.1, PHP-CS-Fixer 3.x.

## 10. Open questions
- PHP version on www.freifunk-karte.de (constrains the upload endpoint, step 8).
- Runtime of one full legacy run on the LXC (baseline for the speed-up; the audit of ~1150 URLs takes about 45 s in parallel).
- Nodelist schema: sources deliver versions 1.0.0 and 1.0.1, the legacy validator only knows 1.0.0. Do 1.0.1 files pass? (check in step 3/4)
- Is `result_statistics.json` still needed once the admin UI exists (step 7)?
