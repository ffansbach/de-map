<?php
/**
 * @var nodeListParser $parser
 */
$ffffm = new stdClass();
$ffffm->name = 'Frankfurt am Main';
$ffffm->nameShort = 'Frankfurt am Main';
$ffffm->url = 'http://www.projekt2k.de/externes/ffkarte/fetchffm.php';
$ffffm->parser = 'Ffmap';
$parser->addAdditional('ffffm', $ffffm);

$ff_ruhrg_fb = new stdClass();
$ff_ruhrg_fb->name = 'Freifunk Ruhrgebiet - FB';
$ff_ruhrg_fb->nameShort = 'Freifunk Ruhrgebiet - FB';
$ff_ruhrg_fb->url = 'http://map.freifunk-ruhrgebiet.de/data/';
$ff_ruhrg_fb->parser = 'Ffmap';
$parser->addAdditional('ff_ruhrg_fb', $ff_ruhrg_fb);

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

$ff_nw = new stdClass();
$ff_nw->name = 'Freifunk Nordwest';
$ff_nw->nameShort = 'Nordwest';
$ff_nw->url = 'http://srv11.ffnw.de/nodelist_api/nodelist.json';
$ff_nw->homePage = 'http://www.ffnw.de';
$ff_nw->parser = 'nodelist';
$parser->addAdditional('ff_nw', $ff_nw);

$ff_nu = new stdClass();
$ff_nu->name = 'Freifunk Niersufer';
$ff_nu->nameShort = 'Niersufer';
$ff_nu->url = 'http://api.freifunk-niersufer.de/niers/nodes.json';
$ff_nu->homePage = 'http://www.freifunk-niersufer.de';
$ff_nu->parser = 'Ffmap';
$parser->addAdditional('ff_nu', $ff_nu);

$ff_nr = new stdClass();
$ff_nr->name = 'Freifunk Niederrhein';
$ff_nr->nameShort = 'Niederrhein';
$ff_nr->url = 'http://map.freifunk-niederrhein.de/data/fl/nodes.json';
$ff_nr->homePage = 'http://www.freifunk-niederrhein.de';
$ff_nr->parser = 'Ffmap';
$parser->addAdditional('ff_nr', $ff_nr);

$ff_wt = new stdClass();
$ff_wt->name = 'Freifunk Wuppertal';
$ff_wt->nameShort = 'Wuppertal';
$ff_wt->url = 'https://map.freifunk-wuppertal.net/data/nodes.json';
$ff_wt->homePage = 'http://www.freifunk-wuppertal.net';
$ff_wt->parser = 'Ffmap';
$parser->addAdditional('ff_wt', $ff_wt);

$ff_ulm = new stdClass();
$ff_ulm->name = 'Freifunk Ulm';
$ff_ulm->nameShort = 'Ulm';
$ff_ulm->url = 'https://map.freifunk-ulm.de/meshviewer/nodes.json';
$ff_ulm->homePage = 'http://www.freifunk-ulm.de';
$ff_ulm->parser = 'Ffmap';
$parser->addAdditional('ff_ulm', $ff_ulm);

$ff_stu = new stdClass();
$ff_stu->name = 'Freifunk Stuttgart';
$ff_stu->nameShort = 'Stuttgart';
$ff_stu->url = 'https://netinfo.freifunk-stuttgart.de/json/global_map.json';
$ff_stu->homePage = 'http://www.freifunk-stuttgart.de';
$ff_stu->parser = 'nodelist';
$parser->addAdditional('ff_stu', $ff_stu);

foreach (["wtbg","sdlh","hd","mdb","doerfer","ln","hlb","bs","mb","mq","su","wa","ar"] as $index => $identifier) {
    ${'ff_winterb_'.$index} = new stdClass();
    ${'ff_winterb_'.$index}->name = 'Freifunk Winterberg '.$identifier;
    ${'ff_winterb_'.$index}->nameShort = 'Winterberg'.$identifier;
    ${'ff_winterb_'.$index}->url = 'https://map.freifunk-winterberg.net/data/'.$identifier.'/meshviewer.json';
    ${'ff_winterb_'.$index}->homePage = 'https://map.freifunk-winterberg.net/';
    ${'ff_winterb_'.$index}->parser = 'Ffmap';
    $parser->addAdditional('ff_winterberg_'.$index, ${'ff_winterb_'.$index});
}
