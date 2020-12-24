<?php
require __DIR__ . '/vendor/autoload.php';
require_once 'config.php';

use phpseclib\Net\SFTP;

if (empty($serverUpload)) {
    return;
}

$sftp = new SFTP($serverUpload['host']);

if (!$sftp->login($serverUpload['user'], $serverUpload['password'])) {
    exit('Login Failed');
}

$sftp->chdir($serverUpload['target']);
$files = ['result_communities.json', 'result_routers.json', 'result_statistics.json'];

foreach ($files as $fileName) {
    print_r('uploading: '.$fileName."\n");
    $sftp->put(
        $fileName.'.staging',
        'cache/'.$fileName,
        SFTP::SOURCE_LOCAL_FILE
    );
    print_r('upload done'."\n");
}

foreach ($files as $fileName) {
    print_r('renaming: "'.$fileName.'.staging" to "'.$fileName.'"'."\n");

    if (!$sftp->delete($fileName)) {
        print_r('delete failed for: "'.$fileName."\n");
        continue;
    }

    if (!$sftp->rename(
        $fileName.'.staging',
        $fileName
    )) {
        print_r('rename failed for: "'.$fileName."\n");
        continue;
    }

    print_r('rename done'."\n");
}
