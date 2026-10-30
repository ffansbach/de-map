<?php

header('Content-Type: text/html; charset=utf-8');

require 'config.php';

?><!doctype html>
<html lang="de">
	<head>
		<meta charset="utf-8">
		<title>Freifunk-Karte</title>
		<meta name="description" content="Karte der Freifunk Zugangspunkte in Deutschland. Öffentlich zugängliche, nicht kommerzielle und unzensierte WLAN Zugangspunkte. ">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.min.css" integrity="sha384-HSMxcRTRxnN+Bdg0JdbxYKrThecOKuH5zCYotlSAcp1+c8xmyTe9GYg1l9a69psu" crossorigin="anonymous">
		<link rel="stylesheet" href="css/site.css" />

		<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous" />
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
								<li role="presentation"><a href="#about" aria-controls="about" role="tab" data-toggle="tab" data-tabsource="fetch.php?content=about">Über die Karte</a></li>
								<li role="presentation"><a href="#stats" aria-controls="stats" role="tab" data-toggle="tab" data-tabsource="fetch.php?content=stats">Statistik</a></li>
							</ul>

							<!-- Tab panes -->
							<div class="tab-content">
								<div role="tabpanel" class="tab-pane active" id="home">
									<h3>Direktlink zur aktuellen Ansicht:</h3>
									<p><span id="direktlink"></span></p>
									<h3>Statistisches</h3>
									<p>Communities im Api-File: <span id="countCom"></span><br />
									Verarbeitete Knoten: <span id="countNodes"></span></p>
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
							Bitte haben Sie einen Moment Geduld. Die Daten werden geladen.
						</p>
						<p>
							Vielen Dank für Ihre Geduld.
						</p>
					</div>
				</div>
			</div>
		</div>

		<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs" crossorigin="anonymous"></script>
		<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/js/bootstrap.min.js" integrity="sha384-aJ21OjlMXNL5UyIl/XNwTMqvzeRMZH2w8c5cRVpzpU8Y5bApTppSuUkhZXN0VxHd" crossorigin="anonymous"></script>
		<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
		<script src="js/prunecluster/PruneCluster.js"></script>
		<script src="js/heatmap.min.js"></script>
		<script src="js/leaflet-heatmap.js"></script>
		<script src="js/graham_scan.js"></script>

		<script src="js/meta_map.js"></script>
		<script src="js/site.js"></script>
		<script>
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
