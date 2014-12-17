<?php

header('Content-Type: text/html; charset=utf-8');

require 'config.php';

?><!doctype html>
<html lang="de">
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<title>Freifunk-Karte</title>
		<meta name="description" content="Karte der Freifunk Zugangspunkte in Deutschland. Öffentlich zugängliche, nicht kommerzielle und unzensierte WLAN Zugangspunkte. ">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap.min.css">
		<link rel="stylesheet" href="css/site.css" />

		<link rel="stylesheet" href="http://cdn.leafletjs.com/leaflet-0.7.3/leaflet.css" />
		<link rel="stylesheet" href="css/MarkerCluster.Default.css" />
		<link rel="stylesheet" href="css/MarkerCluster.css" />
	</head>
	<body>
		<div id="map"></div>

		<!-- Button trigger modal -->
		<button type="button" class="btn btn-info" data-toggle="modal" id="toList" data-target="#myModal">
			<span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span> Infos zur Karte
		</button>

		<!-- Modal -->
		<div class="modal fade" id="myModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
			<div class="modal-dialog">
				<div class="modal-content">
					<div class="modal-header">
						<button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span class="sr-only">Close</span></button>
						<h2 class="modal-title" id="myModalLabel">Über die Karte</h2>
					</div>
					<div class="modal-body">
						<h3>Wie es funktioniert</h3>
						<p>
							Die Karte nutzt die <a href="https://github.com/freifunk/directory.api.freifunk.net" target="_blank">Freifunk Api</a> um eine Liste der
							Communities in Deutschland zu beziehen. Aus deren API-Files werden dann die Links zu Knotenkarten gelesen.
						</p>
						<p>
							3 Arten von Knotenkarten können dann zum Beziehen von Daten genutzt werden: Netmon, ffmap und OpenWifiMap.
							Die dort gezeigten Knoten/Router/Zugangspunkte der jeweiligen Community fließen dann in diese Karte ein.
						</p>
						<p>
							Alle 24 Stunden werden die Daten neu verarbeitet und so aktualisiert.
						</p>
						<h3>Wer hat es gebaut</h3>
						<p>
							Tino Dietel<br />
							tino [at] freifunk-emskirchen.de<br/>
							<a href="http://www.freifunk-emskirchen.de">Freifunk Emskirchen</a></br>
							<a href="https://github.com/stilgarbf">https://github.com/stilgarbf/</a></br><br>
						</p>
						<h3>Technik</h3>
						<ul>
							<li>Leaflet <a href="http://leafletjs.com/" targte="_blank">http://leafletjs.com/</a></li>
							<li>Leaflet-Markercluster <a href="https://github.com/Leaflet/Leaflet.markercluster" targte="_blank">https://github.com/Leaflet/Leaflet.markercluster</a></li>

							<li>Bootstrap <a href="http://getbootstrap.com/" targte="_blank">http://getbootstrap.com/</a></li>
							<li>simpleCachedCurl <a href="https://github.com/ginader/simpleCachedCurl" targte="_blank">https://github.com/ginader/simpleCachedCurl/</a></li>
						</ul>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-default">Schließen</button>
						<a class="btn btn-info" data-dismiss="modal" href="http://www.freifunk-emskirchen.de/de-map/" target="ffems">Ausführliche Informationen</a>
					</div>
				</div>
			</div>
		</div>

		<div class="modal fade" id="waitModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
			<div class="modal-dialog">
				<div class="modal-content">
					<div class="modal-header">
						<h2 class="modal-title" id="myModalLabel">Daten werden geladen</h2>
					</div>
					<div class="modal-body">
						<div style="text-align:center"><img src="img/ajax-loader.gif" alt="spinner" /></div>
						<p>
							Bitte haben Sie einen Moment Geduld. Die Daten werden geladen und unter Umständen neu verarbeitet.
						</p>
						<p>
							Falls Sie bisher gelesen haben werden die Daten tatsächlich neu von allen Communities eingelesen und verarbeitet.
							Dies passiert alle 24 Stunden bei einem zufälligen Seitenaufruf.
							Das Los ist auf Sie gefallen. Sie haben also gewonnen und sorgen nun so dafür, dass die nächsten Besucher die Karte deutlich schneller sehen werden.
						</p>
						<p>
							Nach spätestens einer Minute werden Sie die Freifunk-Knoten angezeigt bekommen.
						</p>
						<p>
							Vielen Dank für Ihre Geduld und Mithilfe.
						</p>
					</div>
				</div>
			</div>
		</div>

		<script src="https://code.jquery.com/jquery-1.11.1.min.js"></script>
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/js/bootstrap.min.js"></script>
		<script src="http://cdn.leafletjs.com/leaflet-0.7.3/leaflet.js"></script>
		<script src="js/leaflet.markercluster-src.js"></script>

		<script src="js/meta_map.js"></script>
		<script>
			<?php /*var communities = <?php echo json_encode($parseResult['communities'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);?>;
			var allTheRouters = <?php echo json_encode($parseResult['routerList']);?>;*/?>
			var tileServerUrl = <?php echo json_encode($tileServerUrl);?>;
			var tileServerAttribution = <?php echo json_encode($tileServerAttribution);?>;
			var mapInitalView = <?php echo json_encode($mapInitalView);?>;
		</script>
	</body>
</html>
