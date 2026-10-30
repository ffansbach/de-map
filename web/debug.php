<?php
// What the parser found out about a community in its last run (cache/result_debug.json).
// Self-contained and read-only: no config.php, no database. Errors are never shown to visitors.
error_reporting(-1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Europe/Berlin');

function e($text)
{
	return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// only http(s) addresses become links
function link_to($url)
{
	$url = (string) $url;

	if (!preg_match('~^https?://~i', $url)) {
		return e($url);
	}

	return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($url) . '</a>';
}

function number($value)
{
	return number_format((int) $value, 0, ',', '.');
}

$outcomes = array(
	'ok' => 'Knoten gefunden',
	'no-data' => 'Die Karte liefert keine Knotendaten',
	'api-unavailable' => 'Die API-Datei der Community war nicht erreichbar',
	'no-nodemaps' => 'In der API-Datei ist keine Knotenkarte eingetragen',
	'skipped' => 'Wird bewusst übersprungen',
);

$reasons = array(
	'page-without-data' => 'Unter der eingetragenen Adresse antwortet eine Webseite, aber auf keinem der bekannten Pfade wurde eine Knotenliste gefunden.',
	'empty-data' => 'Die Datei mit den Knoten ist erreichbar, enthält aber keine Knoten.',
	'not-found' => 'Der Server antwortet, aber an keinem der bekannten Orte liegt eine Knotenliste.',
	'unreachable' => 'Der Server der Karte antwortet nicht (nicht erreichbar, Zertifikatsfehler oder Zeitüberschreitung).',
	'nothing-tried' => 'Es gab keine nutzbare Karten-Adresse, die abgefragt werden konnte.',
);

$file = __DIR__ . '/cache/result_debug.json';
$data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
$communities = is_array($data) && isset($data['communities']) && is_array($data['communities']) ? $data['communities'] : array();
$generated = is_array($data) && isset($data['generated']) ? (string) $data['generated'] : '';
$time = $generated !== '' ? date('d.m.Y H:i', (int) strtotime($generated)) : '';

$selected = isset($_GET['c']) && is_string($_GET['c']) && isset($communities[$_GET['c']]) ? $_GET['c'] : null;

$byMeta = array();
foreach ($communities as $key => $community) {
	$meta = isset($community['meta']) && is_string($community['meta']) && $community['meta'] !== '' ? $community['meta'] : 'ohne Metacommunity';
	$byMeta[$meta][$key] = isset($community['name']) ? (string) $community['name'] : (string) $key;
}
ksort($byMeta, SORT_NATURAL | SORT_FLAG_CASE);

?><!doctype html>
<html lang="de" id="debug">
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<title>Freifunk-Karte Debugview</title>
		<meta name="robots" content="noindex">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css" integrity="sha384-HSMxcRTRxnN+Bdg0JdbxYKrThecOKuH5zCYotlSAcp1+c8xmyTe9GYg1l9a69psu" crossorigin="anonymous">
		<link rel="stylesheet" href="css/site.css" />
	</head>
	<body>
		<div class="container" style="margin-top: 20px">
			<div class="jumbotron">
				<h1>Freifunk-Karte Debugview</h1>
				<p>Was hat der Parser beim letzten Lauf über eine Community herausgefunden?<?php if ($time !== '') { ?> Letzter Lauf: <?php echo e($time); } ?></p>
				<p><a href="./">Zur Karte</a></p>
			</div>

<?php if ($communities === array()) { ?>
			<div class="alert alert-warning">Es liegen noch keine Parserdaten vor.</div>
<?php } else { ?>
			<form method="get" class="form-inline">
				<div class="form-group">
					<label for="c">Community</label>
					<select class="form-control" id="c" name="c" onchange="this.form.submit()">
						<option value="">-- wählen --</option>
<?php foreach ($byMeta as $meta => $members) { ?>
						<optgroup label="<?php echo e($meta); ?>">
<?php foreach ($members as $key => $name) { ?>
							<option value="<?php echo e($key); ?>"<?php echo (string) $key === $selected ? ' selected' : ''; ?>><?php echo e($name); ?></option>
<?php } ?>
						</optgroup>
<?php } ?>
					</select>
				</div>
				<noscript><button type="submit" class="btn btn-default">Anzeigen</button></noscript>
			</form>
			<hr>
<?php } ?>

<?php if ($selected !== null) {
	$c = $communities[$selected];
	$outcome = isset($c['outcome']) ? (string) $c['outcome'] : '';
	$rows = array(
		'Community' => e(isset($c['name']) ? $c['name'] : $selected),
		'Metacommunity' => e(isset($c['meta']) && $c['meta'] ? $c['meta'] : '–'),
		'Auf der Karte gelistet' => !empty($c['listed']) ? 'ja' : 'nein',
		'API-Datei' => !empty($c['apiUrl']) ? link_to($c['apiUrl']) : '–',
		'Angegebene Kartentypen' => e(!empty($c['declaredTypes']) ? implode(', ', (array) $c['declaredTypes']) : '–'),
		'Format der gelieferten Daten' => e(!empty($c['format']) ? $c['format'] : '–'),
	);
	?>
			<h2><?php echo e(isset($c['name']) ? $c['name'] : $selected); ?></h2>
			<p class="lead"><?php echo e(isset($outcomes[$outcome]) ? $outcomes[$outcome] : $outcome); ?></p>
<?php if (!empty($c['reason']) && isset($reasons[$c['reason']])) { ?>
			<p><strong><?php echo e($reasons[$c['reason']]); ?></strong></p>
<?php } ?>
<?php if (!empty($c['staleSince'])) { ?>
			<div class="alert alert-warning">Der Server antwortet derzeit nicht richtig. Auf der Karte stehen deshalb die Knoten des letzten erfolgreichen Abrufs (seit <?php echo e(date('d.m.Y H:i', (int) strtotime((string) $c['staleSince']))); ?>).</div>
<?php } ?>

			<table class="table table-condensed">
<?php foreach ($rows as $label => $value) { ?>
				<tr><th style="width: 30%"><?php echo e($label); ?></th><td><?php echo $value; ?></td></tr>
<?php } ?>
			</table>

<?php if ($outcome === 'ok') { ?>
			<h3>Knoten</h3>
			<table class="table table-condensed">
				<tr><th style="width: 30%">Laut Communityfile</th><td><?php echo isset($c['claimedNodes']) && $c['claimedNodes'] !== null ? e(number($c['claimedNodes'])) : '–'; ?></td></tr>
				<tr><th>In den Daten gefunden</th><td><?php echo e(number(isset($c['found']) ? $c['found'] : 0)); ?></td></tr>
				<tr><th>Auf die Karte übernommen</th><td><?php echo e(number(isset($c['added']) ? $c['added'] : 0)); ?></td></tr>
				<tr><th>Schon von einer anderen Community geliefert (Duplikate)</th><td><?php echo e(number(isset($c['duplicates']) ? $c['duplicates'] : 0)); ?></td></tr>
				<tr><th>Ohne Standort (nicht auf der Karte)</th><td><?php echo e(number(isset($c['withoutLocation']) ? $c['withoutLocation'] : 0)); ?></td></tr>
				<tr><th>Zu lange offline (nicht mehr angezeigt)</th><td><?php echo e(number(isset($c['dropped']) ? $c['dropped'] : 0)); ?></td></tr>
				<tr><th>Unbrauchbare Einträge</th><td><?php echo e(number(isset($c['invalid']) ? $c['invalid'] : 0)); ?></td></tr>
			</table>
<?php } ?>

<?php if (!empty($c['sourceUrls'])) { ?>
			<h3>Woher die Knoten stammen</h3>
			<ul>
<?php foreach ((array) $c['sourceUrls'] as $url) { ?>
				<li><?php echo link_to($url); ?></li>
<?php } ?>
			</ul>
<?php } ?>

<?php if (!empty($c['attempts'])) { ?>
			<h3>Was abgefragt wurde</h3>
			<div class="table-responsive">
				<table class="table table-condensed table-striped">
					<tr><th>Adresse</th><th>Antwort</th><th>Sah aus wie</th><th>Einträge</th></tr>
<?php foreach ((array) $c['attempts'] as $a) { ?>
					<tr>
						<td style="word-break: break-all"><?php echo link_to(isset($a['url']) ? $a['url'] : ''); ?></td>
						<td><?php echo e(isset($a['status']) && $a['status'] !== null ? $a['status'] : (isset($a['error']) && $a['error'] ? $a['error'] : 'keine Antwort')); ?></td>
						<td><?php echo e(isset($a['format']) ? $a['format'] : ''); ?></td>
						<td><?php echo e(isset($a['nodes']) ? $a['nodes'] : 0); ?></td>
					</tr>
<?php } ?>
				</table>
			</div>
<?php } ?>

<?php if (!empty($c['warnings'])) { ?>
			<h3>Auffälligkeiten in den Daten</h3>
			<ul>
<?php foreach ((array) $c['warnings'] as $w) { ?>
				<li><?php echo e($w); ?></li>
<?php } ?>
			</ul>
<?php } ?>

<?php if (!empty($c['messages'])) { ?>
			<h3>Protokoll des Laufs</h3>
			<pre style="max-height: 300px; overflow: auto"><?php echo e(implode("\n", (array) $c['messages'])); ?></pre>
<?php } ?>
<?php } ?>
		</div>
	</body>
</html>
