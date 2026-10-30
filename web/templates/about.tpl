<h3>Wie es funktioniert</h3>
<h4>Datenquelle</h4>
<p>
	Die Karte nutzt die <a href="https://github.com/freifunk/directory.api.freifunk.net" target="_blank">Freifunk Api</a> um eine Liste der
	Communities in Deutschland zu beziehen. Aus deren API-Files werden dann die Links zu Knotenkarten gelesen.
</p>
<p>
	Hinter diesen Links wird nach Knotenlisten gesucht. Das Format wird an den Daten selbst erkannt: &quot;nodelist&quot;
	(empfohlen), die nodeinfo-Daten von ffmap, Meshviewer und HopGlass sowie die flache meshviewer.json.
	Die dort gezeigten Knoten/Router/Zugangspunkte der jeweiligen Community fließen dann in diese Karte ein.
</p>
<p>
	Communities, die keine dieser Kartenlösungen verwenden, können eine Knotenliste im Format &quot;nodelist&quot; anbieten.<br>
	Informationen zur nodelist gibt es auf <a href="https://github.com/ffansbach/nodelist" target="_blank" rel="noopener">github -&gt; nodelist</a>.
</p>
<h4>Verarbeitung</h4>
<p>
	Alle 60 Minuten werden die Daten neu verarbeitet und so aktualisiert.
</p>
<p>
	Antwortet der Server einer Community vorübergehend nicht, werden bis zu 24 Stunden lang ihre zuletzt bekannten Daten gezeigt.
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
	<li>Leaflet <a href="http://leafletjs.com/" target="_blank" rel="noopener">http://leafletjs.com/</a></li>
	<li>PruneCluster <a href="https://github.com/SINTEF-9012/PruneCluster" target="_blank" rel="noopener">https://github.com/SINTEF-9012/PruneCluster</a></li>
	<li>heatmap.js und Leaflet Heatmap Overlay <a href="https://www.patrick-wied.at/static/heatmapjs/" target="_blank" rel="noopener">https://www.patrick-wied.at/static/heatmapjs/</a></li>
	<li>Bootstrap <a href="http://getbootstrap.com/" target="_blank" rel="noopener">http://getbootstrap.com/</a></li>
</ul>
<h3>Attribution</h3>
<p>
	Icon zum Zentrieren der Karte made by <a href="http://www.icons8.com" title="Icons8">Icons8</a> - <a href="http://www.flaticon.com" title="Flaticon">www.flaticon.com</a> lizensiert unter <a href="http://creativecommons.org/licenses/by/3.0/" title="Creative Commons BY 3.0">CC BY 3.0</a>
</p>
<a class="btn btn-info" href="http://www.freifunk-emskirchen.de/de-map/" target="ffems">Ausführliche Informationen</a>
