<script src="js/flot/jquery.flot.min.js"></script>
<script src="js/flot/jquery.flot.time.min.js"></script>
<h3>Entwicklung der Knotenzahl</h3>
<div id="flot_target" style="width:100%;height:400px"></div>
<p>
	Seit 25.02.2015 werden Knoten, die länger als 3 Tage offline sind, ausgeblendet.
</p>
<p>
	Gezeigt werden die Minimal- und Maximalwerte pro Tag.<br>
	Kurzfristige Spitzen nach unten resultieren üblicherweise aus Verfügbarkeitsproblemen bei einzelnen API-Files.
	Die Knoten waren in der Zeit normalerweise online.
</p>

<script>

var dataMin = {
		data: <?php echo json_encode($data[0]);?>,
		label: ' Tagesminimum',
		lines: {
			lineWidth : 1
		},
		color : '#666'
	};
var dataMax = {
		data: <?php echo json_encode($data[1]);?>,
		label: ' Tagesmaximum',
		lines: {
			lineWidth : 2
		},
		color : '#dc0067'
	};

$(function() {
	$.each(dataMin.data, function(i, e){
		e[0] *= 1000;
	});
	$.each(dataMax.data, function(i, e){
		e[0] *= 1000;
	});

	var data = [ dataMax, dataMin ];
	var monthNames = ["Jan", "Feb", "Mär", "Apr", "Mai", "Jun", "Jul", "Aug", "Sep", "Okt", "Nov", "Dez"];

	var options = {
		xaxis: {
			mode: "time",
			monthNames: monthNames
		},
		lines: {
			show: true
		},
		grid: {
			hoverable: true
		}
	};
	$.plot($("#flot_target"), data, options);

	$("#flot_target").bind("plothover", function (event, pos, item)
	{
		if (item)
		{
			var date = item.datapoint[0],
				nodes = item.datapoint[1];
			var dateO = new Date(date);
			var minmax = item.seriesIndex == 0 ? 'maximal' : 'minimal';
			var dateStr = pad(dateO.getDate(), 2)+'.'+pad(dateO.getMonth()+1, 2)+'.'+dateO.getFullYear();

			$("#fltooltip").html(minmax + ' ' + nodes + " Knoten am " + dateStr)
				.css({top: item.pageY+5, left: item.pageX+15})
				.fadeIn(200);
		}
		else
		{
			$("#fltooltip").hide();
		}
	});

	if(!$('#fltooltip').length)
	{
		$("<div id='fltooltip'></div>").css({
			position: "absolute",
			display: "none",
			border: "1px solid #fdd",
			padding: "2px",
			"background-color": "#fee",
			opacity: 0.80,
			zIndex: 2000
		}).appendTo("body");
	}
});

function pad(num, size)
{
    var s = "0" + num;
    return s.substr(s.length-size);
}
</script>