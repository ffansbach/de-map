<h3>Wie es funktioniert</h3>
<h4>Datenquelle</h4>
<p>
	Die Karte nutzt die <a href="https://github.com/freifunk/directory.api.freifunk.net" target="_blank">Freifunk Api</a> um eine Liste der
	Communities in Deutschland zu beziehen. Aus deren API-Files werden dann die Links zu Knotenkarten gelesen.
</p>
<p>
	3 Arten von Knotenkarten können dann zum Beziehen von Daten genutzt werden: Netmon, ffmap und OpenWifiMap.
	Die dort gezeigten Knoten/Router/Zugangspunkte der jeweiligen Community fließen dann in diese Karte ein.
</p>
<p>
	Als viertes Format wird &quot;nodelist&quot; verarbeitet, welches für alle Communities sinnvoll ist, die keine der 3 Kartenlösungen verwenden.<br>
	Informationen zur nodelist gibt es auf <a href="https://github.com/ffansbach/nodelist" target="_blank">github -&gt; nodelist</a>.
</p>
<h4>Verarbeitung</h4>
<p>
	Alle 60 Minuten werden die Daten neu verarbeitet und so aktualisiert.
</p>
<p>
	Knoten, die länger offline sind, werden nicht auf der Karte angezeigt.
	Falls bekannt ist, dass sie weniger als 3 Tage offline sind, werden sie grau dargestellt.
</p>
<h3>Wer hat es gebaut</h3>
<p>
	Tino Dietel<br />
	tino [at] freifunk-emskirchen.de<br/>
	<a href="http://www.freifunk-emskirchen.de">Freifunk Emskirchen</a><br />
	<a href="https://github.com/stilgarbf">https://github.com/stilgarbf/</a><br /><br />
</p>
<p>
	<i>Heatmap Layer:</i><br />
	Alexander Wunschik<br />
	freifunk [at] wunschik.net<br/>
	<a href="https://wiki.freifunk-franken.de/w/Benutzer:DelphiN">Freifunk Franken</a><br />
	<a href="https://github.com/mojoaxel">https://github.com/mojoaxel/</a><br /><br />
</p>
<h3>Technik</h3>
<ul>
	<li>Leaflet <a href="http://leafletjs.com/" targte="_blank">http://leafletjs.com/</a></li>
	<li>Leaflet-Markercluster <a href="https://github.com/Leaflet/Leaflet.markercluster" targte="_blank">https://github.com/Leaflet/Leaflet.markercluster</a></li>

	<li>Bootstrap <a href="http://getbootstrap.com/" targte="_blank">http://getbootstrap.com/</a></li>
</ul>
<h3>Attribution</h3>
<p>
	Icon zum Zentrieren der Karte made by <a href="http://www.icons8.com" title="Icons8">Icons8</a> - <a href="http://www.flaticon.com" title="Flaticon">www.flaticon.com</a> lizensiert unter <a href="http://creativecommons.org/licenses/by/3.0/" title="Creative Commons BY 3.0">CC BY 3.0</a>
</p>
<a class="btn btn-info" href="http://www.freifunk-emskirchen.de/de-map/" target="ffems">Ausführliche Informationen</a>
