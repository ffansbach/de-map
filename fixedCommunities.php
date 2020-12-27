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

$ff_kssl = new stdClass();
$ff_kssl->name = 'Freifunk Kassel';
$ff_kssl->nameShort = 'Kassel';
$ff_kssl->url = 'http://www.projekt2k.de/externes/ffkarte/fetchkassel.php';
$ff_kssl->homePage = 'https://freifunk-kassel.de/';
$ff_kssl->parser = 'nodelist';
$parser->addAdditional('ff_kassel', $ff_kssl);

$ff_nu = new stdClass();
$ff_nu->name = 'Freifunk Niersufer';
$ff_nu->nameShort = 'Niersufer';
$ff_nu->url = 'https://map.freifunk-niersufer.de/data/meshviewer.json';
$ff_nu->homePage = 'http://www.freifunk-niersufer.de';
$ff_nu->parser = 'Ffmap';
$parser->addAdditional('ff_nu', $ff_nu);

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

$ff_lp = new stdClass();
$ff_lp->name = 'Freifunk Lippe';
$ff_lp->nameShort = 'Lippe ';
$ff_lp->url = 'https://map.freifunk-lippe.de/map/data/meshviewer.json';
$ff_lp->homePage = 'http://freifunk-lippe.de/';
$ff_lp->parser = 'Ffmap';
$parser->addAdditional('ff_lp', $ff_lp);

$ff_eb = new stdClass();
$ff_eb->name = 'Freifunk Einbeck';
$ff_eb->nameShort = 'Einbeck ';
$ff_eb->url = 'http://vps643489.ovh.net/meshviewer/data/meshviewer.json';
$ff_eb->homePage = 'https://freifunk-einbeck.de/';
$ff_eb->parser = 'Ffmap';
$parser->addAdditional('ff_eb', $ff_eb);

$ff_en = new stdClass();
$ff_en->name = 'Freifunk Ennepetal';
$ff_en->nameShort = 'Ennepetal ';
$ff_en->url = 'https://karte.ff-en.de/data/meshviewer.json';
$ff_en->homePage = 'https://freifunk-en.de/';
$ff_en->parser = 'Ffmap';
$parser->addAdditional('ff_en', $ff_en);
