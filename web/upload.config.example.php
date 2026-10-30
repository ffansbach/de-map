<?php

/**
 * Copy to upload.config.php (next to upload.php) and fill in. The file only returns an array, so a
 * request for it shows nothing; still keep it out of version control (it holds the secret).
 *
 * Make the secret with:  bin/parser upload:secret   (and put the same one into config/upload.local.json)
 */
return array(
    'secret' => 'REPLACE WITH THE OUTPUT OF bin/parser upload:secret',
    // where the map reads its result files (the cache/ directory of the web part)
    'target' => __DIR__ . '/cache',
    // where used one-time values are remembered (replay protection); optional, the default is
    // .upload-state inside the target directory. Only empty files named nonce-... live there.
    // 'stateDir' => '/var/lib/ffk-upload',
    // 'requireHttps' => true,
    // behind a reverse proxy that terminates TLS: 'trustForwardedProto' => true,
    // the upload is refused if it holds fewer than this (the same guard data.php had)
    'minCommunities' => 10,
    'minRouters' => 1000,
    // ... or fewer than this share of the nodes that are there now (0 switches the check off)
    'maxShrink' => 0.5,
);
