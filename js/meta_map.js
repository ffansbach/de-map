// docready
$(function() {
	init();
	icons = prepareIcon();

	$.ajax({
		url: 'data.php',
		data: window.location.search.substring(1),
		beforeSend: function()
		{
			ajaxModalTimeout = window.setTimeout("$('#waitModal').modal('show');", 1000);
		},
		success: function(response)
		{
			communities = response.communities;
			metaCommunities = response.metaCommunities;
			addPoints2Map(response.allTheRouters);
		},
		complete : function()
		{
			window.clearTimeout(ajaxModalTimeout);
			$('#waitModal').modal('hide')
		}
	});

});

/**
 * the leaflet map
 *
 * @var {object}
 */
var map;

/**
 * will hold the icons for nodes as created by prepareIcon()
 *
 * @var {array}
 */
var icons;

/**
 * all the communities
 *
 * @var {object}
 */
var communities, metaCommunities; // todo: make this none global

/**
 * a window timeout used for the modal shown if the ajax-fetch takes very long
 *
 * @var {timeout}
 */
var ajaxModalTimeout;

/**
 * will hold the layer-control
 *
 * @var {leafletControl}
 */
var layerControl;

/**
 * stores marker and area, that indicate tzhe user position
 * after the map has been centered
 *
 * @var {object}
 */
var locMarker, locArea;

/**
 * will contain the outer hull of a cluster
 */
var clusterArea;

/*
 * configure prunecluster
 */
PruneCluster.Cluster.ENABLE_MARKERS_LIST = true;

/**
 * initialize map
 *
 * prepare mapcontainer
 * set tile-layer
 */
function init()
{
	extendIcons();

	var requestedLat = parseFloat(getURLParameter('lat'));
	var requestedLng = parseFloat(getURLParameter('lng'));
	var requestedZoom = parseInt(getURLParameter('z'));

	var startView = {
		latitude: mapInitalView.latitude,
		longitude: mapInitalView.longitude,
		zoom: mapInitalView.zoom
	};

	if(requestedLat && requestedLng)
	{
		startView.latitude = requestedLat;
		startView.longitude = requestedLng;
	}

	if(requestedZoom)
	{
		startView.zoom = requestedZoom;
	}

	map = L.map('map').setView([startView.latitude, startView.longitude], startView.zoom);

	L.tileLayer(tileServerUrl,
	{
		attribution: tileServerAttribution,
		maxZoom: 18
	}).addTo(map);

	map.addControl(centerOnPosition);

	map.on('moveend overlayadd overlayremove', setDirectLink);

	setDirectLink();

}

/**
 * set some informations to the stat-tab in modal
 * @param {int} comCount  community count
 * @param {int} nodeCount node count
 */
function setStats(comCount, nodeCount)
{
	$('#countCom').text(comCount);
	$('#countNodes').text(nodeCount);
}

/**
 * creates a link for the current view and sets it in the modal
 */
function setDirectLink()
{
	var roundBy = 100000;
	var z = map.getZoom();
	var pos = map.getCenter();
	var lat = Math.round(pos.lat * roundBy) / roundBy;
	var lng = Math.round(pos.lng * roundBy) / roundBy;

	var layers = [];

	if(typeof layerControl != 'undefined')
	{
		// check for every layer if it is active
		$.each(layerControl._layers, function(key, item)
		{
			if(map.hasLayer(item.layer))
			{
				layers.push(item.name);
			}
		});
	}

	layers = layers.join('|');

	var newLink = document.location.origin + document.location.pathname
					+'?lat='+lat+'&lng='+lng+'&z='+z;

	// add layers only to query string if any is selected and its not only the default one
	if(layers != '' && layers != 'Nodes')
	{
		newLink = newLink+'&l='+layers;
	}

	$('#direktlink').text(newLink);
}

/**
 * returns icons for online/offline routers
 * @return {object} object with 2 leaflet-icons
 */
function prepareIcon()
{
	var icon = L.icon({
		iconUrl: 'img/hotspot.png',
		iconSize:     [42, 27], // size of the icon
		iconAnchor:   [21, 13], // point of the icon which will correspond to marker's location
		popupAnchor:  [0, -9] // point from which the popup should open relative to the iconAnchor
	});

	var icon_off = L.icon({
		iconUrl:'img/hotspot_offline.png',
		iconSize:     [42, 27], // size of the icon
		iconAnchor:   [21, 13], // point of the icon which will correspond to marker's location
		popupAnchor:  [0, -9] // point from which the popup should open relative to the iconAnchor
	});

	return {hotspot:icon, hotspotOffline:icon_off};
}

/**
 * parse data and add points to cluster-layer
 * @param {object} data
 */
function addPoints2Map(data)
{
	var pruneCluster = preparePruneCluster();

	// prepare heatmap overlay
	var heatmapLayer = new HeatmapOverlay({
		"radius": 30,
		"scaleRadius": false,
		"useLocalExtrema": false
	});
	var heatMapData = [];

	// add all entries to clustergroup and heatmap
	$.each(data, function(i, router)
	{
		heatMapData.push({
			lat: router.lat,
			lng: router.long,
			count: 1.1
		});

		var marker = new PruneCluster.Marker(router.lat, router.long);

		marker.category = 0;
		marker.data.icon = icons.hotspot;

		if(router.status == 'online')
		{
			marker.category = 1;
		}
		else if(router.status == 'offline')
		{
			marker.category = 2;
			marker.data.icon = icons.hotspotOffline;
		}

		marker.data.name = router.name;
		marker.data.popup = getTooltipContent(router);

		pruneCluster.RegisterMarker(marker);
	});


	// + Finally add the pruneCluster to the map Object.
	map.addLayer(pruneCluster);

	// add heatmap layer
	map.addLayer(heatmapLayer);
	heatmapLayer.setData({
		max: 1,
		data: heatMapData
	});

	var layers = {
		// add the cluster layer
		"Nodes": pruneCluster,

		// add the heatmap layer
		"HeatMap": heatmapLayer
	};

	var selectedLayers = getURLParameter('l');

	if(selectedLayers)
	{
		// layers have been preselected in the url
		selectedLayers = selectedLayers.split('|');

		$.each(layers, function(key, layer)
		{
			if($.inArray(key, selectedLayers) != -1)
			{
				map.addLayer(layer);
			}
			else
			{
				map.removeLayer(layer);
			}
		});
	}
	else
	{
		// hide heatmap layer by default
		map.removeLayer(heatmapLayer);
	}

	// add layer controls for all layers
	layerControl = L.control.layers({}, layers).addTo(map);

	// update stats
	var countCom = 0;
	for (var k in communities)
	{
		if (communities.hasOwnProperty(k))
		{
			++countCom;
		}
	}

	setStats(countCom, data.length);
	setDirectLink();
}

/**
 * get tooltip-html for router
 *
 * @param  {object} routerData
 * @return {string}
 */
function getTooltipContent(routerData)
{
	var tooltip = '<h3 class="router">'+routerData.name+'</h3>';

	if(typeof communities[routerData.community] != 'undefined')
	{
		var thisRouterCommunity = communities[routerData.community];
		tooltip += '<h4 class="comm" title="Community">Freifunk-Gruppe: <a href="'+thisRouterCommunity.url+'" target="community_netmon">'+thisRouterCommunity.name+'</a></h4>';

		if(thisRouterCommunity.meta !== false)
		{
			var metaName = thisRouterCommunity.meta;

			if(
				typeof metaCommunities[metaName] != 'undefined'
				&&
				typeof metaCommunities[metaName].url != 'undefined'
			)
			{
				// add link to metacommunity
				metaName = '<a href="'+metaCommunities[metaName].url+'" target="community_netmon">'+metaName+'</a>';
			}

			tooltip += '<h4 class="comm" title="Metacommunity">Übergruppe: '+metaName+'</h4>';
		}

	}
	tooltip += '<p>';

	if(routerData.clients != '?')
	{
		tooltip += 'verbundene Clients: '+routerData.clients+'<br />';
	}

	if(routerData.status != 'online' && routerData.status != '?')
	{
		tooltip += '<span class="errorNote">Router ist offline !</span>';
	}
	else if(routerData.status == '?')
	{
		tooltip += 'Routerstatus unbekannt';
	}

		tooltip += '</p>';

	return tooltip;
}

/**
 * returns the value of a getparameter from the url
 *
 * uf no url is given, doc.loc.href is used
 *
 * @param  {string} name
 * @param  {string|boolean} url
 * @return string|null
 */
function getURLParameter(name, url)
{
	if(typeof url == 'undefined' || !url)
	{
		var url = document.location.href;
	}

	return (RegExp(name + '=' + '(.+?)(&|$)').exec(url)||[,null])[1];
}

/*
 * icon/Button zum Zentrieren der Karte
 */
L.Control.CenterOnPosition = L.Control.extend(
{
	options:
	{
		position: 'topleft',
	},
	onAdd: function (map)
	{
		var controlDiv = L.DomUtil.create('div', 'leaflet-draw-toolbar leaflet-bar');

		L.DomEvent
			.addListener(controlDiv, 'click', L.DomEvent.stopPropagation)
			.addListener(controlDiv, 'click', L.DomEvent.preventDefault)
			.addListener(controlDiv, 'click', function () {
				map.locate(
				{
					setView: true,
					maxZoom: 18
				});
			});

		var controlUI = L.DomUtil.create('a', 'leaflet-center-on-position', controlDiv);
			controlUI.title = 'Auf Position zentrieren';
			controlUI.href = '#';

		map.on('locationfound', onLocationFound);

		return controlDiv;
	}
});

/**
 * eventhandler called when a location is found
 *
 * this is triggered after the user has clicked the "center on position"
 * button a valid position has been found.
 *
 * @param  {event} e
 */
function onLocationFound(e)
{
	// remove previously added location-layers
	if(locArea)
	{
		map.removeLayer(locArea);
		map.removeLayer(locMarker);
	}

	locMarker = L.marker(e.latlng)
					.addTo(map)
					.bindPopup("Du bist vermutlich innerhalb dieses Kreises")
					.openPopup();

	// draw a circle around the position indication accuracy
	locArea = L.circle(e.latlng, (e.accuracy / 2)).addTo(map);

	window.setTimeout(function(){map.removeLayer(locArea);}, 3000);
}

var centerOnPosition = new L.Control.CenterOnPosition();

/**
 * shows the area of the hovered cluster
 * @param  {object} cluster
 */
function showClusterHull(cluster)
{
	var convexHull = new ConvexHullGrahamScan();

	$.each(cluster._clusterMarkers, function(i, marker)
	{
		convexHull.addPoint(marker.position.lat, marker.position.lng);
	});

	var hullPoints = convexHull.getHull();
	var points = [];

	$.each(hullPoints, function(i, point)
	{
		points.push([point.x, point.y]);
	});

	clusterArea = L.polygon(points,{
		color: '#009ee0',
		fillColor: '#009ee0',
		fillOpacity: 0.5
	}).addTo(map);
}

/**
 * extend L.Icon for PruneCluster
 */
function extendIcons()
{
	// category-colors: status unknown, online, offline
	var colors = ['#ff4b00', '#dc0067', '#666666'],
		pi2 = Math.PI * 2;

	L.Icon.MarkerCluster = L.Icon.extend(
	{
		options:
		{
			iconSize: new L.Point(44, 44),
			className: 'prunecluster leaflet-markercluster-icon'
		},
		createIcon: function ()
		{
			// based on L.Icon.Canvas from shramov/leaflet-plugins (BSD licence)
			var e = document.createElement('canvas');
			this._setIconStyles(e, 'icon');
			var s = this.options.iconSize;
			e.width = s.x;
			e.height = s.y;
			this.draw(e.getContext('2d'), s.x, s.y);
			return e;
		},
		createShadow: function ()
		{
			return null;
		},
		draw: function (canvas, width, height)
		{
			var lol = 0;
			var start = 0;

			for (var i = 0, l = colors.length; i < l; ++i)
			{
				var size = this.stats[i] / this.population;

				if (size > 0)
				{
					canvas.beginPath();
					canvas.moveTo(22, 22);
					canvas.fillStyle = colors[i];
					var from = start + 0.14,
						to = start + size * pi2;

					if (to < from)
					{
						from = start;
					}

					start = start + size * pi2;

					canvas.arc(22, 22, 18, from, to);
					canvas.lineTo(22, 22);
					canvas.fill();
					canvas.closePath();
				}
			}

			canvas.beginPath();
			canvas.fillStyle = 'white';
			canvas.arc(22, 22, 14, 0, Math.PI * 2);
			canvas.fill();
			canvas.closePath();
			canvas.fillStyle = '#666666';
			canvas.textAlign = 'center';
			canvas.textBaseline = 'middle';
			canvas.font = 'bold 12px sans-serif';
			canvas.fillText(this.population, 22, 22, 40);
		}
	});
}

/**
 * creates a prune-cluster
 *
 * @return {object}
 */
function preparePruneCluster()
{
	// +--- Init the prune Cluste Plugin for Leaflet: https://github.com/SINTEF-9012/PruneCluster---------------
	var pruneCluster = new PruneClusterForLeaflet();

	pruneCluster.BuildLeafletCluster = function(cluster, position)
	{
		var m = new L.Marker(position,
		{
			icon: pruneCluster.BuildLeafletClusterIcon(cluster)
		});

		m.on('click', function()
		{
			map.removeLayer(clusterArea);

			// Compute the  cluster bounds (it's slow : O(n))
			var markersArea = pruneCluster.Cluster.FindMarkersInArea(cluster.bounds);
			var b = pruneCluster.Cluster.ComputeBounds(markersArea);

			if (b)
			{
				var bounds = new L.LatLngBounds(
					new L.LatLng(b.minLat, b.maxLng),
					new L.LatLng(b.maxLat, b.minLng));

				var zoomLevelBefore = pruneCluster._map.getZoom();
				var zoomLevelAfter = pruneCluster._map.getBoundsZoom(bounds, false, new L.Point(20, 20, null));

				// If the zoom level doesn't change
				if (zoomLevelAfter === zoomLevelBefore)
				{
					// Send an event for the LeafletSpiderfier
					pruneCluster._map.fire('overlappingmarkers', {
						cluster: pruneCluster,
						markers: markersArea,
						center: m.getLatLng(),
						marker: m
					});

					pruneCluster._map.setView(position, zoomLevelAfter);
				}
				else
				{
					pruneCluster._map.fitBounds(bounds);
				}
			}
		})
		.on('mouseover', function()
		{
			showClusterHull(cluster);
		})
		.on('mouseout', function()
		{
			map.removeLayer(clusterArea);
		});

		return m;
	};

	// + Make a custom Icon for the Cluster. Also Taken from: https://github.com/SINTEF-9012/PruneCluster
	pruneCluster.BuildLeafletClusterIcon = function (cluster)
	{
		var e = new L.Icon.MarkerCluster();
		e.stats = cluster.stats;
		e.population = cluster.population;
		return e;
	};

	pruneCluster.Cluster.Size = 100;

	return pruneCluster;
}
