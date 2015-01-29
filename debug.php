<?php

header('Content-Type: text/html; charset=utf-8');

require 'config.php';

?><!doctype html>
<html lang="de" style="height:auto; overflow:auto">
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<title>Freifunk-Karte-Debugview</title>
		<meta name="description" content="Karte der Freifunk Zugangspunkte in Deutschland. Öffentlich zugängliche, nicht kommerzielle und unzensierte WLAN Zugangspunkte. ">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap.min.css">
		<link rel="stylesheet" href="css/site.css" />
	</head>
	<body style="height:auto">
		<div class="container" style="margin-top: 20px">

			<div class="jumbotron">
				<h1>Freifunk-Karte Debugview</h1>
				<p id="jumbotext"></p>
			</div>

			<form>
				<div class="row">
					<div class="form-group col-md-6">
						<label for="communities">Meta-Communities</label>
						<select class="form-control" id="meta_communities">
							<option value="0">-- alle zeigen --</option>
							<option value="-1">-- ohne Metacommunity --</option>
						</select>
					</div>
					<div class="form-group col-md-6">
						<label for="communities">Communities</label>
						<select class="form-control" id="communities">
						</select>
					</div>
				</div>

					<hr>

				<div id="details">
					<div class="row">
						<div class="form-group col-md-6">
							<label>Communityname</label>
							<input class="form-control" id="name">
						</div>
						<div class="form-group col-md-6">
							<label>Metacommunity</label>
							<input class="form-control" id="metacommunity">
						</div>
					</div>
					<div class="row">
						<div class="form-group col-md-6">
							<label>Nodes laut Communityfile</label>
							<input class="form-control" id="nodes">
						</div>
						<div class="form-group col-md-6">
							<label>Communityfile</label>
							<input class="form-control" id="cfile">
						</div>
					</div>
					<div class="row">

						<div class="form-group col-md-12">
							<label>Logeinträge</label>
							<textarea class="form-control" id="log" rows="10"></textarea>
						</div>
					</div>
				</div>
			</form>
		</div><!-- /.container -->

		<script src="https://code.jquery.com/jquery-1.11.1.min.js"></script>
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/js/bootstrap.min.js"></script>

		<script>
		/**
		 * initialize
		 */
		$(function(){
			$.ajax({
				dataType: "json",
				url: 'fetch.php?get_debug',
				success: processResult,
				cache: false
			});

			$('#communities').change(processChange);
			$('#meta_communities').change(processMeta);
		})

		/**
		 * response-handler for the ajax-result
		 *
		 * @param  string
		 */
		function processResult(res)
		{
			$('#jumbotext').text('Letzter Parserlauf: '+res.timestamp);
			var $select = $('#communities');

			window.communities = res.errorCommunities;

			var metacommunities = [];

			var newOption = $('<option>', {
				text : '-- wählen --',
				value : 0
			})
			.appendTo($select);

			// append all communities as options to the select
			$.each(res.errorCommunities, function(key, value)
			{
				var newOption = $('<option>', {
					text : key,
					value : key
				})
				.appendTo($select);

				// check for metacommunity
				if(
					typeof value.metacommunity != 'undefined'
					&&
					value.metacommunity != ''
					&&
					$.inArray(value.metacommunity, metacommunities) == -1
					)
				{
					metacommunities.push(value.metacommunity);
				}
			});

			$select = $('#meta_communities');
			metacommunities.sort();

			// add metacommunities to select
			$.each(metacommunities, function(key, value)
			{
				var newOption = $('<option>', {
					text : value,
					value : value
				}).appendTo($select);
			});

		}

		/**
		 * changehandler for metac.select
		 */
		function processMeta()
		{
			var selected = $(this).val();

			var $select = $('#communities');
				$select.find('option').remove();

			var newOption = $('<option>', {
				text : '-- wählen --',
				value : 0
			})
			.appendTo($select);

			// update/filter cummunity-list
			$.each(communities, function(key, value)
			{
				if(
					selected == 0						// show all
					||
					value.metacommunity == selected		// show selected
					||
					(
						selected == -1					// show all without metacommunity
						&&
						typeof value.metacommunity == 'undefined'
					)
				)
				{
					var newOption = $('<option>', {
						text : key,
						value : key
					})
					.appendTo($select);
				}
			});

			$select.change();

		}

		/**
		 * changehandler for community-select
		 *
		 * shows debug-data for the selected community
		 */
		function processChange()
		{
			var selected = $(this).val();
			var selectedCommunity = communities[selected];

			$('#metacommunity, #log, #name, #nodes, #cfile').val('');

			if(selected == 0)
			{
				return;
			}

			$('#name').val(selectedCommunity.name);
			$('#nodes').val(selectedCommunity.claimed_nodecount);
			$('#cfile').val(selectedCommunity.apifile);

			if(typeof selectedCommunity.metacommunity != undefined)
			{
				$('#metacommunity').val(selectedCommunity.metacommunity);
			}

			var logMessages = '';

			$.each(selectedCommunity.message, function(index, value){
				logMessages += value+"\n";
			});

			$('#log').val(logMessages);

			console.dir(communities[selected]);
		}
		</script>
		<?php
			if(!empty($trackingCode))
			{
				echo $trackingCode;
			}
		?>
	</body>
</html>
