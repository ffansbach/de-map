<?php

defined('APP_STARTED') or die('Direct call is not allowed');

require_once __DIR__ . '/vendor/autoload.php';
require_once 'config.php';

if (empty($serverUpload)) {
    return;
}
echo "\n**************************\n";
echo "starting result upload\n";

// ftp connect
$conn_id = ftp_connect($serverUpload['host']);
$login_result = ftp_login($conn_id, $serverUpload['user'], $serverUpload['password']);
ftp_pasv($conn_id, true);

if ((!$conn_id) || (!$login_result)) {
    exit('FTP login failed');
}

$files = ['result_communities.json', 'result_routers.json', 'result_statistics.json'];

// uploading all files as filename.json.staging
foreach ($files as $fileName) {
    echo 'uploading: '.$fileName."\n";
    $fp = fopen(__DIR__.'/cache/'.$fileName, 'r');

    if (ftp_fput($conn_id, $fileName.'.staging', $fp, FTP_TEXT)) {
        echo "Successfully uploaded $fileName\n";
    } else {
        echo "There was a problem while uploading $fileName\n";
    }

    echo 'upload done'."\n";
}

$fileList = ftp_rawlist($conn_id, '/');

// renaming all filename.json.staging to filename.json
foreach ($files as $fileName) {
    echo 'renaming: "'.$fileName.'.staging" to "'.$fileName.'"'."\n";

    if (in_array($fileName, $fileList)) {
        // file already exists - delete the old one before renaming new
        if (!ftp_delete($fileName)) {
            echo 'delete failed for: ' . $fileName . "\n";
            continue;
        }
    }

    if (!ftp_rename(
        $conn_id,
        $fileName.'.staging',
        $fileName
    )) {
        echo 'rename failed for: '.$fileName."\n";
        continue;
    }

    echo 'rename done'."\n";
}

// close the connection
ftp_close($conn_id);
