<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once __DIR__ . '/backend_guard.php';
require_once __DIR__ . '/../../includes/db/archive.php';

// What follows replaces db/wallos.db, which is the whole restore on SQLite and
// reaches nothing on PostgreSQL — the file the running instance never reads.
// So on any other backend the rows go back instead (issue #23).
//
// One transaction, and the archive is validated before a single row is
// removed: a restore that stops halfway leaves an installation that is neither
// the old one nor the new one, which is worse than one that refused.
if (!wallos_db_file_backup_supported($db)) {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== 0) {
        http_response_code(400);
        die(json_encode([
            "success" => false,
            "message" => translate('no_file_uploaded', $i18n)
        ]));
    }

    $uploaded = $_FILES['file']['tmp_name'];

    if (!is_uploaded_file($uploaded)) {
        http_response_code(400);
        die(json_encode(["success" => false, "message" => translate('error', $i18n)]));
    }

    $restored = wallos_archive_import($db, $uploaded, __DIR__ . '/../../images/uploads');

    if (!$restored['success']) {
        http_response_code(400);
        error_log('Wallos restore: ' . (string) $restored['error']);
        die(json_encode([
            "success" => false,
            "message" => translate('restore_failed', $i18n) . ' ' . (string) $restored['error']
        ]));
    }

    die(json_encode([
        "success" => true,
        "message" => translate('restore_successful', $i18n),
        "tables" => $restored['tables'],
        "rows" => $restored['rows']
    ]));
}

function emptyRestoreFolder()
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator('../../.tmp', RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo) {
        $removeFunction = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $removeFunction($fileinfo->getRealPath());
    }
}

if (isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $fileTmpName = $file['tmp_name'];
    $fileError = $file['error'];

    if ($fileError === 0) {
        $fileDestination = '../../.tmp/restore.zip';
        move_uploaded_file($fileTmpName, $fileDestination);

        $zip = new ZipArchive();
        if ($zip->open($fileDestination) === true) {
            // Validate all entries before extracting — ZipArchive::extractTo() does not
            // guarantee protection against path traversal (Zip Slip), and the extraction
            // target sits under the web root, so a crafted archive could otherwise drop
            // an executable script here (RCE).
            // Extensions the web server may execute if extracted into a servable path.
            // .tmp/ is denied at the nginx layer as the primary control; this is defense
            // in depth for other deployments (Apache).
            $blockedExtensions = [
                'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht',
                'phps', 'phar', 'shtml', 'cgi', 'pl', 'py', 'sh',
                'htaccess', 'htpasswd'
            ];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = str_replace('\\', '/', $zip->getNameIndex($i));
                if ($entry === '' || $entry[0] === '/' || in_array('..', explode('/', $entry), true)) {
                    $zip->close();
                    emptyRestoreFolder();
                    die(json_encode([
                        "success" => false,
                        "message" => "Invalid backup file: unsafe file path detected."
                    ]));
                }
                if (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $blockedExtensions, true)) {
                    $zip->close();
                    emptyRestoreFolder();
                    die(json_encode([
                        "success" => false,
                        "message" => "Invalid backup file: disallowed file type detected."
                    ]));
                }
            }
            $zip->extractTo('../../.tmp/restore/');
            $zip->close();
        } else {
            die(json_encode([
                "success" => false,
                "message" => "Failed to extract the uploaded file"
            ]));
        }

        if (file_exists('../../.tmp/restore/wallos.db')) {
            $db->close();

            if (file_exists('../../db/wallos.db')) {
                unlink('../../db/wallos.db');
            }
            rename('../../.tmp/restore/wallos.db', '../../db/wallos.db');

            $db = new SQLite3('../../db/wallos.db');
            $db->busyTimeout(5000);
            ob_start();
            require_once __DIR__ . '/../../includes/run_migrations.php';
            ob_end_clean();

            if (file_exists('../../.tmp/restore/logos/')) {
                $dir = '../../images/uploads/logos/';
                $di = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
                $ri = new RecursiveIteratorIterator($di, RecursiveIteratorIterator::CHILD_FIRST);

                foreach ($ri as $file) {
                    if ($file->isDir()) {
                        rmdir($file->getPathname());
                    } else {
                        unlink($file->getPathname());
                    }
                }

                $dir = new RecursiveDirectoryIterator('../../.tmp/restore/logos/');
                $ite = new RecursiveIteratorIterator($dir);
                $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

                foreach ($ite as $filePath) {
                    if (in_array(pathinfo($filePath, PATHINFO_EXTENSION), $allowedExtensions)) {
                        $destination = str_replace('../../.tmp/restore/', '../../images/uploads/', $filePath);
                        $destinationDir = pathinfo($destination, PATHINFO_DIRNAME);

                        if (!is_dir($destinationDir)) {
                            mkdir($destinationDir, 0755, true);
                        }

                        copy($filePath, $destination);
                    }
                }
            }

            emptyRestoreFolder();

            echo json_encode([
                "success" => true,
                "message" => translate("success", $i18n)
            ]);
        } else {
            emptyRestoreFolder();

            die(json_encode([
                "success" => false,
                "message" => "wallos.db does not exist in the backup file"
            ]));
        }


    } else {
        echo json_encode([
            "success" => false,
            "message" => "Failed to upload file"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "message" => "No file uploaded"
    ]);
}