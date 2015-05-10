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
	</head>
	<body>
		<div id="map"></div>

		<!-- Button trigger modal -->
		<button type="button" class="btn btn-info" data-toggle="modal" id="toList" data-target="#informationModal">
			<span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span> Infos zur Karte
		</button>

		<!-- Modal -->
		<div class="modal fade" id="informationModal" tabindex="-1" role="dialog" aria-labelledby="informationModalLabel" aria-hidden="true">
			<div class="modal-dialog">
				<div class="modal-content">
					<div class="modal-header">
						<button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span class="sr-only">Close</span></button>
						<h2 class="modal-title" id="informationModalLabel">Über die Karte</h2>
					</div>
					<div class="modal-body">
						<div role="tabpanel">

							<!-- Nav tabs -->
							<ul class="nav nav-tabs modalMainTabs" role="tablist">
								<li role="presentation" class="active"><a href="#home" aria-controls="home" role="tab" data-toggle="tab">Infos</a></li>
								<li role="presentation"><a href="#about" aria-controls="profile" role="tab" data-toggle="tab" data-tabsource="fetch.php?content=about">Über die Karte</a></li>
								<li role="presentation"><a href="#stats" aria-controls="profile" role="tab" data-toggle="tab" data-tabsource="fetch.php?content=stats&2">Statistik</a></li>
							</ul>

							<!-- Tab panes -->
							<div class="tab-content">
								<div role="tabpanel" class="tab-pane active" id="home">
									<h3>Direktlink zur aktuellen Ansicht:</h3>
									<p><span id="direktlink"></span></p>
									<h3>Statistisches</h3>
									<ul>Communities im Api-File: <span id="countCom"></ul>
									<ul>Verarbeitete Knoten: <span id="countNodes"></ul>
									<h3>Download</h3>
									<p><a href="fetch.php?content=gpxfile">Alle Knoten als GPX-Datei</a></p>
									<p><a href="#" id="gpxlink">Aktuell sichtbare Knoten als GPX-Datei</a></p>
								</div>
								<div role="tabpanel" class="tab-pane" id="about"></div>
								<div role="tabpanel" class="tab-pane" id="stats"></div>
							</div>

						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-default" data-dismiss="modal">Schließen</button>
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
		<script src="js/prunecluster/PruneCluster.js"></script>
		<script src="js/heatmap.min.js"></script>
		<script src="js/leaflet-heatmap.js"></script>
		<script src="js/graham_scan.js"></script>

		<script src="js/meta_map.js"></script>
		<script src="js/site.js"></script>
		<script>
			<?php /*var communities = <?php echo json_encode($parseResult['communities'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);?>;
			var allTheRouters = <?php echo json_encode($parseResult['routerList']);?>;*/?>
			var tileServerUrl = <?php echo json_encode($tileServerUrl);?>;
			var tileServerAttribution = <?php echo json_encode($tileServerAttribution);?>;
			var mapInitalView = <?php echo json_encode($mapInitalView);?>;
		</script>
		<?php
			if(!empty($trackingCode))
			{
				echo $trackingCode;
			}
		?>
	</body>
</html>
