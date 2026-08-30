<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once __DIR__ . '/backend_guard.php';
require_once __DIR__ . '/../../includes/db/archive.php';

// On anything other than SQLite the file copy below has nothing to copy: db/
// holds only setup_token.db, and the archive it would build is a valid zip with
// no data in it. It used to be streamed anyway and called a success, then
// refused honestly since 5.8.2, and now it takes the route that works on every
// backend — the rows themselves (issue #23).
//
// SQLite keeps the file copy. It restores faster, it is what existing archives
// are, and an installation that has one should not find out at restore time
// that the format changed under it.
if (!wallos_db_file_backup_supported($db)) {
    $archivePath = tempnam(sys_get_temp_dir(), 'wallos_archive_');

    if ($archivePath === false) {
        http_response_code(500);
        die(json_encode([
            "success" => false,
            "message" => translate('cannot_open_zip', $i18n)
        ]));
    }

    $written = wallos_archive_export($db, $archivePath, __DIR__ . '/../../images/uploads');

    if (!$written['success']) {
        @unlink($archivePath);
        http_response_code(500);
        error_log('Wallos backup: could not write the archive: ' . (string) $written['error']);
        die(json_encode([
            "success" => false,
            "message" => translate('backup_failed', $i18n)
        ]));
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    clearstatcache(true, $archivePath);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="Wallos-Backup-' . date('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($archivePath));
    header('Cache-Control: no-store');

    // Unlinked before a byte is sent: with the unlink after the stream, an
    // aborted download kept the full archive forever (#85). The open handle
    // keeps the bytes alive exactly as long as the request sending them.
    $archive = fopen($archivePath, 'rb');
    unlink($archivePath);

    if ($archive !== false) {
        fpassthru($archive);
        fclose($archive);
    }
    exit;
}

function addFolderToZip($dir, $zipArchive, $zipdir = '')
{
    if (is_dir($dir)) {
        if ($dh = opendir($dir)) {
            //Add the directory
            if (!empty($zipdir))
                $zipArchive->addEmptyDir($zipdir);
            while (($file = readdir($dh)) !== false) {
                // Skip '.' and '..'
                if ($file == "." || $file == "..") {
                    continue;
                }
                //If it's a folder, run the function again!
                if (is_dir($dir . $file)) {
                    $newdir = $dir . $file . '/';
                    addFolderToZip($newdir, $zipArchive, $zipdir . $file . '/');
                } else {
                    //Add the files
                    $zipArchive->addFile($dir . $file, $zipdir . $file);
                }
            }
        }
    } else {
        die(json_encode([
            "success" => false,
            "message" => "Directory does not exist: $dir"
        ]));
    }
}

// Build the archive OUTSIDE the web root. Previously it was written to
// ../../.tmp/ with a uniqid()-based name and served statically by nginx, which
// let anyone who could guess the (timestamp-derived, low-entropy) filename
// download the full database unauthenticated. The backup is now streamed
// directly to the authenticated admin below and never persists in a
// web-accessible location.
$zipname = tempnam(sys_get_temp_dir(), 'wallos_backup_');
if ($zipname === false) {
    die(json_encode([
        "success" => false,
        "message" => translate('cannot_open_zip', $i18n)
    ]));
}

$zip = new ZipArchive();
if ($zip->open($zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    @unlink($zipname);
    die(json_encode([
        "success" => false,
        "message" => translate('cannot_open_zip', $i18n)
    ]));
}

addFolderToZip('../../db/', $zip);
addFolderToZip('../../images/uploads/', $zip);

if ($zip->close() === false) {
    @unlink($zipname);
    die(json_encode([
        "success" => false,
        "message" => "Failed to finalize the zip file"
    ]));
}

// Discard any buffered output (e.g. a stray newline from an included file)
// so it cannot corrupt the binary archive that follows.
while (ob_get_level() > 0) {
    ob_end_clean();
}

// ZipArchive wrote through its own handle after tempnam() created the file at
// 0 bytes, so clear the stat cache before reading its size for Content-Length.
clearstatcache(true, $zipname);

$downloadName = 'Wallos-Backup-' . date('Ymd-His') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipname));
header('Cache-Control: no-store');

// Same as the row-based branch above: the path goes first, the open handle
// streams, and an aborted download leaves nothing behind.
$archive = fopen($zipname, 'rb');
unlink($zipname);

if ($archive !== false) {
    fpassthru($archive);
    fclose($archive);
}
exit;