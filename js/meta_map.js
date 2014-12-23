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
			addPoints2Map(response.allTheRouters);
		},
		complete : function()
		{
			window.clearTimeout(ajaxModalTimeout);
			$('#waitModal').modal('hide')
		}
	});

});

var map;
var icons;
var communities; // todo: make this none global
var ajaxModalTimeout;

/**
 * initialize map
 *
 * prepare mapcontainer
 * set tile-layer
 */
function init()
{
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

	map.on('moveend', setDirectLink);

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

	var newLink = document.location.origin + document.location.pathname
					+'?lat='+lat+'&lng='+lng+'&z='+z;
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
	// prepare cluster overlay
	var markers = L.markerClusterGroup(
	{
		maxClusterRadius: function(zoom)
		{
			var clusterRadius = 70;
			if(zoom == 18)
			{
				clusterRadius = 10;
			}
			else if(zoom >= 16)
			{
				clusterRadius = 40;
			}

			return clusterRadius;
		}
	});
	
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
		var markerSettings = {
			title: router.name,
			icon : icons.hotspot
		};

		if(router.status != 'online' && router.status != '?')
		{
			// router is not online. ignore unknown, simply show as offline
			markerSettings.icon = icons.hotspotOffline;
		}

		var marker = L.marker(
			new L.LatLng(router.lat, router.long),
			markerSettings
		);

		marker.bindPopup(getTooltipContent(router));
		markers.addLayer(marker);
		
		// heatmap data
		heatMapData.push({
			lat: router.lat, 
			lng: router.long, 
			count: 1.1
		});
	});

	// add layer with clustergroup to map
	map.addLayer(markers);
	
	
	// add heatmap layer	
	map.addLayer(heatmapLayer);
	heatmapLayer.setData({
		max: 1,
		data: heatMapData
	});
	// hide heatmap layer by default
	map.removeLayer(heatmapLayer);

	// add layer controls for all layers
	L.control.layers({
		//no baselayers to select yet
	}, {
		// add the cluster layer
		"Nodes": markers,
		
		// add the heatmap layer
		"HeatMap": heatmapLayer
	}).addTo(map);
	
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
}

/**
 * get tooltip-html for router
 *
 * @param  {object} routerData
 * @return {string}
 */
function getTooltipContent(routerData)
{
	var thisRouterCommunity = communities[routerData.community];
	var tooltip = '<h3 class="router">'+routerData.name+'</h3>';
		tooltip += '<h4 class="comm"><a href="'+thisRouterCommunity.url+'" target="community_netmon">'+thisRouterCommunity.name+'</a></h4>';
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