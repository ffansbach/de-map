# History of the modernisation

Notes from the time the old PHP parser was replaced (October 2026): what the old code did wrong, how the new pipeline was checked against it, and the one-time rollout runbooks. They are kept as a record; nothing here is needed to run or change the project today.

The old parser itself is in Git (it was removed in commit `05c4d83`, `git show 05c4d83^:lib/NodeListParser.php`). Section numbers inside this file are those of the former `PRD.md`; the living design, the decisions and the open items are in [`../PRD.md`](../PRD.md).

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
| `lib/log.php` | Type hint `mysqli` resolves to `ffmap\mysqli`, so passing a real `\mysqli` is a TypeError. **Fixed 2026-10-31** by adopting the version that runs in production (plain global class, no type hints, weekly grouping). |
| `lib/HealthCheck.php` | `ping()` throws `ErrorException` which resolves to `ffmap\ErrorException` (nonexistent), so an empty identifier causes a fatal instead of the intended exception. `$healtcheckIdentifier` is missing in `config.php_example.php`. |
| `lib/InfluxLog.php` | Still imports InfluxDB v1 classes (`InfluxDB\Client`, `Database`, `Point`) that are no longer installed, plus `http\Exception\InvalidArgumentException` (pecl_http). |
| `lib/CurlHelper.php` | TLS verification disabled globally; `CURLOPT_IPRESOLVE` set with `CURL_VERSION_IPV6` (wrong constant); no response size limit; recursive manual redirect handling. |
| `lib/NodeListParser.php` | `getParsed()` discards per-run community diagnostics after one overwritten statistics file, so there is no history. |

PHPStan baseline: none any more (removed 2026-10-31; `lib/log.php` is clean at level 5).

## 9. Legacy characterisation (step 3)

The untouched legacy `NodeListParser` runs against a fake network (`tests/Legacy`): `FakeCurlHelper` answers from a route table and records every request, `LegacyRunner` captures result files, debug messages, echo output and PHP warnings, `World`/`StandardWorld` build a reference scenario of 13 communities and 3 fixed ones from the source fixtures (dates are shifted so offline-node ages stay constant). Goldens live in `tests/fixtures/golden/reference/` (`routers.json`, `communities.json`, `calls_cold.txt`, `calls_warm.txt`); regenerate deliberately with `UPDATE_GOLDEN=1 vendor/bin/phpunit tests/Legacy`.

**Removed in step 9 (2026-10-30):** the legacy parser, `FakeCurlHelper`, `LegacyRunner`, the edge-case and parity tests and the call-count goldens are gone (they are in git before commit `05c4d83`, if the old behaviour is ever needed). Kept: `World`/`StandardWorld`, and the recorded `communities.json`/`routers.json`, which `ParseRunTest` and `ResultWriterTest` hold the new output to. There is no `UPDATE_GOLDEN` any more; the two files are fixed references.

### Rules the rewrite must keep (pinned by tests)
- Nodelist beats every other map of the same community; the other maps are not even fetched.
- Nodes from several communities: identical name+id+coordinates are one node, the first community wins.
- A community whose map delivers nothing is still listed in `result_communities.json`; communities without a loadable API file or without `nodeMaps` are not.
- Offline nodes: nodeinfo and nodelist keep nodes offline for up to 3 days (status `offline`), older or without `lastseen`/`lastcontact` are dropped. Latitude/longitude `0` counts as "no location".
- Client count: number, object with `total`, or list (counted); otherwise 0.
- Output: coordinates are **strings**, `clients` an int, fields `id, lat, long, name, community, status, clients`.
- Probe order for a map page: `config.json` (dataPath), `nodes.json`, `data/nodes.json`, `json/nodes.json`, `meshviewer/data/meshviewer.json`, `data/meshviewer.json`, the URL itself.

### Legacy bugs now proven by tests (expected to change)
| Bug | Evidence | Expected after rewrite |
|-----|----------|------------------------|
| A warm run (everything cached) makes **more** requests than a cold one: 40 vs 37 in the reference world; each direct `.json` source is downloaded 4x instead of 1x | `calls_warm.txt` | warm run needs fewer requests than cold |
| "Already parsed" check never matches direct `.json` maps (normalised dir URL vs stored file URL): a map shared by two communities is fetched twice, only the node hash dedupe saves the result | `dup-a`/`dup-b` | one fetch, message "already parsed" |
| Second `dataPath` of a HopGlass config is ignored (`array_merge` result discarded) | `hop-town`: 4 nodes instead of 8 | all dataPaths used |
| Cached working URL is fetched and thrown away (`getNodesFromCachedFfmapUrl` result unused) | warm run request counts | cache is used instead of probing |
| Nodelist schema validation never rejects (`Jsv4::validate()` returns an object, always truthy; `isValid()` was meant) | `testNodelistSchemaValidationNeverRejects` | real validation (decide in step 4 whether schema 1.0.1 files pass: the sources deliver 1.0.1) |
| `result_metacommunities.json` is never written, so `metaCommunities` is always `false` in the frontend | only 3 result files + `logfile.txt` exist | decide: drop the key, or write it (needs a data source) |
| `//config.json`: normalised URL ends in `/` and `/config.json` is appended | probe list | single slash |
| `ffmapUrlCheck` reads `->nodes` without checking: JSON without `nodes` raises a PHP warning | `testJsonWithoutNodesKeyRaisesAPhpWarning` | no warning, clear message |
| Failed API file downloads are not cached: requested twice per run (nodelist pass + main pass); skipped communities still download their API file | `broken-api`, `skipped-town` | one request per file, none for skipped communities |
| `getParsed(true)` does not force a reparse if the result file is younger than one second (TTL 0 compared with `>`) | harness has to age the files | n/a (the new pipeline has no such cache) |
| Flat `meshviewer.json` nodes that are offline are always dropped, even if seen an hour ago; nodeinfo/nodelist keep them for 3 days | edge cases | one rule for all formats (decide with the operator) |

On healthy input the legacy parser raises no PHP warnings.

### Consequence for the plan
The warm-run finding matters for the speed-up goal: the existing caches do not save requests, they add them. Parallel fetching (step 5) plus a correct cache should reduce both request count and wall time; the baseline to beat is `calls_cold.txt` (37 requests for 13 communities) and the real LXC run time (still unknown).

## From: 10. New core: models and parsers (step 4)

### Differences to the legacy parser (all deliberate, all tested)
| Case | Legacy | New |
|------|--------|-----|
| Flat meshviewer node offline, last seen an hour ago | dropped | kept as `offline` |
| Offline node without a last-seen time (any format) | dropped (nodeinfo, nodelist, flat) | dropped (unchanged) |
| Coordinates outside the valid range | accepted | refused, counted, warning |
| Non-numeric coordinates in a nodelist | accepted | refused |
| Old `geo[]` format | parsed | not supported (no source delivers it) |
| Entry without id | included with empty id | counted as invalid |

(`LegacyParityTest`, removed in step 9) replayed all 32 legacy edge cases and the three fixtures through the new parsers; only the first, fifth and sixth rows show up as differences there.

## From: 11. The new pipeline (step 5)

### Measured against the live network (2026-10-29, dev machine, same minute-scale window)

| | legacy parser | new, cold | new, warm |
|---|---:|---:|---:|
| time | **176.8 s** | **21.6 s** | 20.3 s |
| requests | 1222 | 786 | 687 |
| nodes | 24,394 | 25,160 | 25,160 |
| listed communities | 329 | 336 | 336 |
| peak memory | 54 MB | 47 MB | 49 MB |

(The legacy parser's PHP memory limit is 1 GB, it needs 54 MB. The 3-4 minutes the operator sees on the LXC match the 177 s measured here.)

Where the ~20 s go: 5.8 s for the 337 API files, then rounds of 8.5 s, 5.2 s and 0.4 s. Each round waits for its slowest request, which is usually a dead host running into the 5 s connect timeout. An event-driven scheduler (each map advances on its own) could bring this towards 10 s; not done, the goal (speed-up) is reached.

### Why the two results differ (every difference explained)
Of 24,394 legacy nodes 24,374 are in the new result. The 20 that are not are 12 live changes (airFiber links whose GPS position jitters, one renamed node; two runs minutes apart) and 8 nodes whose coordinates the legacy parser published as garbage and the new one refuses (e.g. latitude `521281725`, longitude `843804`: a missing decimal point). New in the new result (786):

| What | Nodes | Why |
|------|------:|-----|
| karlsruhe | 304 | the legacy parser gave up on its nodelist; the content-based pipeline found the working meshviewer map |
| cuxhaven | 248 | the legacy run hit a host that did not answer at that moment |
| iserlohn | 164 | declares a `nodelist` but serves the flat meshviewer format; the legacy parser read it with the nodelist parser and **skipped all 174 nodes** |
| flat meshviewer, offline but seen within 3 days | 63 | deliberate (one offline rule), e.g. darmstadt +12, ff_nordwest +40, winterberg |
| the same live changes as above, seen from the other side | 7 | position of a moved node |
| the 7 communities of the old skip list | 0 | listed now (their maps deliver nothing) |

Also confirmed: `technicalType` is unreliable (iserlohn), and servers do misbehave (3Ländereck: nodelists flicker between full and empty; `map-data/bh/nodelist.json` was empty for a long time). Both are handled (content-based parsing; per-host limit, retry, last-good).

### Status of the legacy bugs (section 9) in the new code
Fixed: warm run slower than cold (now fewer requests), "already parsed" never matching `.json` maps (a shared map is fetched once), second `dataPath` ignored, cached URL thrown away, nodelist validation (soft, reported), `result_metacommunities.json` never written (written, with links derived from members), `//config.json`, JSON without `nodes` raising a warning, failed API files requested twice, skipped communities still downloading their API file, different offline rules per format, entries without id included. The legacy code ran in production until the cut-over (2026-10-29) and was removed in step 9 (2026-10-30).

### Runbook: new next to old on the LXC
```bash
git pull && composer install --no-dev          # PHP >= 8.3 with curl, mbstring, xml
bin/parser run --out=/srv/ffkarte-new --cache-dir=/srv/ffkarte-state -v
bin/parser compare cache /srv/ffkarte-new      # cache/ is where the legacy parser writes
```
The legacy cron keeps running untouched; `--out` is a separate directory, nothing live is replaced. Run it a few times over a day: the second run uses what the first learned. Things to look at in `compare`: communities that lose nodes (should only be the explained kinds above), `result_statistics.json` → `staleSince` and `outcome: no-data`.

## From: 12. Run history, drift detection and reporting (step 6)

### Checked against the live network
Two consecutive real runs gave identical numbers and no events (24,941 nodes, 336 communities, 269 with nodes). With a doctored history ("yesterday" had another format for Karlsruhe, 900 nodes for Iserlohn, no `hopglass` type) the third real run reported exactly those three as warnings.

## From: 13. Admin UI (step 7)

### Checked against the real history (3 live runs, 336 communities)
Every page answers in 1-20 ms. The R12 view on the live directory: **16 communities with a page but no data** (9 of them on one server, `map.freifunk-rhein-sieg.net`; Leipzig, Siegburg, Troisdorf, Berlin and others), 8 with an empty data file, 15 with nothing at the known places, 15 without any answer, 13 without a usable map; 16 API files unavailable, 24 without maps. The fixed-communities view reproduces the earlier analysis (12 dry, 16 redundant, 11 with nodes of their own). "Dry for N runs" is still short: the history only has a few runs. Look at `/fixed` again after a few weeks before removing entries.

## From: 14. Secure upload (step 8)

### Runbook: rollout
1. `bin/parser upload:secret`; put the value in `web/upload.config.php` on the web host (outside the web root if possible: `stateDir`; deny access to the config) and in `config/upload.local.json` on the LXC.
2. Copy `web/upload.php` and the config to the host. Check: a GET answers 4xx, an unsigned POST answers 401.
3. `bin/parser upload --from=<a recent --out directory>`; compare the map.
4. Run `run --upload` with HTTPS `required: false` next to FTP `required: true` for some days; look at `bin/parser runs --events` for `delivery-partial`.
5. Switch: HTTPS `required: true`, drop FTP.

### Tested
The receiver (36 tests), both publishers (the HTTPS one against the receiver code in-process), the delivery rules, the config loader, the commands, and **the real thing**: PHP's web server running `web/upload.php` with the `upload` command as client (success, wrong secret, no configuration, replay, wrong clock, GET/unsigned POST).
