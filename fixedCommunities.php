<?php
/**
 * @var NodeListParser $parser
 */
$ff_ddfl = new stdClass();
$ff_ddfl->name = 'Freifunk Duesseldorf-Flingern';
$ff_ddfl->nameShort = 'Duesseldorf Flingern';
$ff_ddfl->url = 'https://karte.ffdus.de/data/nodes.json';
$ff_ddfl->homePage = 'http://www.ffdus.de/';
$ff_ddfl->parser = 'Ffmap';
$parser->addAdditional('ff_duesseldorf-flingern', $ff_ddfl);

$ff_wt = new stdClass();
$ff_wt->name = 'Freifunk Wuppertal';
$ff_wt->nameShort = 'Wuppertal';
$ff_wt->url = 'https://map.freifunk-wuppertal.net/data/nodes.json';
$ff_wt->homePage = 'http://www.freifunk-wuppertal.net';
$ff_wt->parser = 'Ffmap';
$parser->addAdditional('ff_wt', $ff_wt);

foreach (["wtbg","sdlh","hd","mdb","doerfer","ln","hlb","bs","mb","mq","su","wa","ar"] as $index => $identifier) {
    ${'ff_winterb_'.$index} = new stdClass();
    ${'ff_winterb_'.$index}->name = 'Freifunk Winterberg '.$identifier;
    ${'ff_winterb_'.$index}->nameShort = 'Winterberg'.$identifier;
    ${'ff_winterb_'.$index}->url = 'https://map.freifunk-winterberg.net/data/'.$identifier.'/meshviewer.json';
    ${'ff_winterb_'.$index}->homePage = 'https://map.freifunk-winterberg.net/';
    ${'ff_winterb_'.$index}->parser = 'Ffmap';
    $parser->addAdditional('ff_winterberg_'.$index, ${'ff_winterb_'.$index});
}

$ff_lz = new stdClass();
$ff_lz->name = 'Freifunk Leipzig';
$ff_lz->nameShort = 'Leipzig';
$ff_lz->url = 'http://db.leipzig.freifunk.net/uptime/hopglass/v2/nodes.json';
$ff_lz->homePage = 'http://leipzig.freifunk.net/';
$ff_lz->parser = 'Ffmap';
$parser->addAdditional('ff_lz', $ff_lz);

$ff_en = new stdClass();
$ff_en->name = 'Freifunk Ennepetal';
$ff_en->nameShort = 'Ennepetal';
$ff_en->url = 'https://karte.ff-en.de/data/meshviewer.json';
$ff_en->homePage = 'https://freifunk-en.de/';
$ff_en->parser = 'Ffmap';
$parser->addAdditional('ff_en', $ff_en);

foreach (range(1, 11) as $index) {
    ${'ff_gt_'.$index} = new stdClass();
    ${'ff_gt_'.$index}->name = 'Freifunk Kreis GT '.$index;
    ${'ff_gt_'.$index}->nameShort = 'Gütersloh'.$index;
    ${'ff_gt_'.$index}->url = 'https://map03.4830.org/data/map_0'.$index.'/nodes.json';
    ${'ff_gt_'.$index}->homePage = 'https://freifunk-kreisgt.de/';
    ${'ff_gt_'.$index}->parser = 'Ffmap';
    $parser->addAdditional('ff_gt_'.$index, ${'ff_gt_'.$index});
}

$ff_weimar = new stdClass();
$ff_weimar->name = 'Freifunk Weimar';
$ff_weimar->nameShort = 'Weimar';
$ff_weimar->url = 'https://hopglass.weimarnetz.de/data/nodes.json';
$ff_weimar->homePage = 'https://weimarnetz.de/';
$ff_weimar->parser = 'Ffmap';
$parser->addAdditional('ff_weimar', $ff_weimar);

$ff_kbumland = new stdClass();
$ff_kbumland->name = 'Freifunk KoelnBonnUmland';
$ff_kbumland->nameShort = 'KBU';
$ff_kbumland->url = 'https://map.kbu.freifunk.net/data/ffkbuu/meshviewer.json';
$ff_kbumland->homePage = 'https://map.kbu.freifunk.net/#!/de/map';
$ff_kbumland->parser = 'Ffmap';
$parser->addAdditional('ff_kbumland', $ff_kbumland);

$ff_euskirchen = new stdClass();
$ff_euskirchen->name = 'Freifunk Euskirchen';
$ff_euskirchen->nameShort = 'Euskirchen';
$ff_euskirchen->url = 'https://map.kbu.freifunk.net/data/ffeu/meshviewer.json';
$ff_euskirchen->homePage = 'https://ffeu.de/';
$ff_euskirchen->parser = 'Ffmap';
$parser->addAdditional('ff_euskirchen', $ff_euskirchen);

$ff_nordwest = new stdClass();
$ff_nordwest->name = 'Freifunk Nordwest';
$ff_nordwest->nameShort = 'Nordwest';
$ff_nordwest->url = 'https://map.ffnw.de/data/meshviewer.json';
$ff_nordwest->homePage = 'https://ffnw.de/';
$ff_nordwest->parser = 'Ffmap';
$parser->addAdditional('ff_nordwest', $ff_nordwest);


$ff_ansbach = new stdClass();
$ff_ansbach->name = 'Freifunk Ansbach';
$ff_ansbach->nameShort = 'Ansbach';
$ff_ansbach->url = 'https://karte.freifunk-ansbach.de/data/nodelist.json';
$ff_ansbach->homePage = 'http://www.freifunk-ansbach.de';
$ff_ansbach->parser = 'nodelist';
$parser->addAdditional('ff_ansbach', $ff_ansbach);

$ff_emskirchen = new stdClass();
$ff_emskirchen->name = 'Freifunk Emskirchen';
$ff_emskirchen->nameShort = 'Emskirchen';
$ff_emskirchen->url = 'https://karte.freifunk-emskirchen.de/data/nodelist.json';
$ff_emskirchen->homePage = 'http://www.freifunk-emskirchen.de';
$ff_emskirchen->parser = 'nodelist';
$parser->addAdditional('ff_emskirchen', $ff_emskirchen);

$ff_heilsbronn = new stdClass();
$ff_heilsbronn->name = 'Freifunk Heilsbronn';
$ff_heilsbronn->nameShort = 'Heilsbronn';
$ff_heilsbronn->url = 'https://karte.freifunk-heilsbronn.de/data/nodelist.json';
$ff_heilsbronn->homePage = 'http://www.freifunk-heilsbronn.de';
$ff_heilsbronn->parser = 'nodelist';
$parser->addAdditional('ff_heilsbronn', $ff_heilsbronn);

$ff_neuendettelsau = new stdClass();
$ff_neuendettelsau->name = 'Freifunk Neuendettelsau';
$ff_neuendettelsau->nameShort = 'Neuendettelsau';
$ff_neuendettelsau->url = 'https://karte.freifunk-neuendettelsau.de/data/nodelist.json';
$ff_neuendettelsau->homePage = 'http://www.freifunk-neuendettelsau.de';
$ff_neuendettelsau->parser = 'nodelist';
$parser->addAdditional('ff_neuendettelsau', $ff_neuendettelsau);

$ff_nea = new stdClass();
$ff_nea->name = 'Freifunk Neustadt Aisch';
$ff_nea->nameShort = 'NEA';
$ff_nea->url = 'https://karte.freifunk-neustadt-aisch.de/data/nodelist.json';
$ff_nea->homePage = 'http://www.freifunk-neustadt-aisch.de';
$ff_nea->parser = 'nodelist';
$parser->addAdditional('ff_nea', $ff_nea);


$ff_goett = new stdClass();
$ff_goett->name = 'Freifunk Göttingen';
$ff_goett->nameShort = 'Göttingen';
$ff_goett->url = 'https://map.cccgoe.de/data/meshviewer.json';
$ff_goett->homePage = 'https://map.cccgoe.de/';
$ff_goett->parser = 'Ffmap';
$parser->addAdditional('ff_goett', $ff_goett);


$ff_lippe = new stdClass();
$ff_lippe->name = 'Freifunk Lippe';
$ff_lippe->nameShort = 'Lippe';
$ff_lippe->url = 'https://map.freifunk-lippe.de/map/data/meshviewer.json';
$ff_lippe->homePage = 'https://map.freifunk-lippe.de/';
$ff_lippe->parser = 'Ffmap';
$parser->addAdditional('ff_lippe', $ff_lippe);
