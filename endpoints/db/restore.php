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
    // Absolute, because this also runs as a shutdown hook and the working
    // directory at shutdown is not guaranteed to be this script's. Under the
    // system temp directory since #86: staging inside the webroot was the
    // last writable path there, and it needed its own mount on a read-only
    // root — one tmpfs at /tmp covers this instead.
    $staging = sys_get_temp_dir() . '/wallos-restore';

    if (!is_dir($staging)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($staging, RecursiveDirectoryIterator::SKIP_DOTS),
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
        $stagingDir = sys_get_temp_dir() . '/wallos-restore';
        if (!is_dir($stagingDir) && !mkdir($stagingDir, 0700, true)) {
            die(json_encode([
                "success" => false,
                "message" => "Could not create the staging directory"
            ]));
        }

        $fileDestination = $stagingDir . '/restore.zip';
        move_uploaded_file($fileTmpName, $fileDestination);

        // From here on the staging directory holds data. Most failure paths
        // below clean up after themselves, but two historically did not — a
        // zip that refuses to open, a migration that fails — and the next
        // path added would be a leak again (#85). Registered once, this runs
        // whatever way the request leaves; by then everything worth keeping
        // has been moved out of the staging directory.
        register_shutdown_function('emptyRestoreFolder');

        $zip = new ZipArchive();
        if ($zip->open($fileDestination) === true) {
            // Validate all entries before extracting — ZipArchive::extractTo() does not
            // guarantee protection against path traversal (Zip Slip). The extraction
            // target used to sit under the web root; it is outside now (#86), and the
            // checks stay as defense in depth: a traversal entry could still escape
            // the staging directory, and the logos copy below moves files into a
            // servable path afterwards.
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
            $zip->extractTo($stagingDir . '/restore/');
            $zip->close();
        } else {
            die(json_encode([
                "success" => false,
                "message" => "Failed to extract the uploaded file"
            ]));
        }

        if (file_exists($stagingDir . '/restore/wallos.db')) {
            $db->close();

            if (file_exists('../../db/wallos.db')) {
                unlink('../../db/wallos.db');
            }
            // rename() across the tmpfs/volume boundary falls back to
            // copy-and-delete for files, which is exactly what is wanted.
            rename($stagingDir . '/restore/wallos.db', '../../db/wallos.db');

            // Upstream's eb0d24b, taken through the boundary: a restored file
            // can be behind the code, so the chain runs before anything reads
            // the schema. The runner's answer is read rather than discarded —
            // a restore that reports success over an unfinished migration is
            // exactly #103, and the buffer is kept so a failure has its
            // diagnosis (see tests/cases/migration_callers_test.php).
            $db = wallos_database_connect(__DIR__ . '/../../db/wallos.db');
            $db->busyTimeout(5000);
            ob_start();
            require_once __DIR__ . '/../../includes/run_migrations.php';
            $migrationOutput = ob_get_clean();

            if ($migrationFailure !== null) {
                error_log('Wallos restore: migrating the restored database failed: ' . $migrationOutput);
                die(json_encode([
                    "success" => false,
                    "message" => "Restored, but migrating the backup failed at "
                        . basename((string) $migrationFailure)
                        . ". The migration is retried on the next container start;"
                        . " the backup's logos were not copied — fix the cause and restore again."
                ]));
            }

            if (file_exists($stagingDir . '/restore/logos/')) {
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

                $dir = new RecursiveDirectoryIterator($stagingDir . '/restore/logos/');
                $ite = new RecursiveIteratorIterator($dir);
                $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

                foreach ($ite as $filePath) {
                    if (in_array(pathinfo($filePath, PATHINFO_EXTENSION), $allowedExtensions)) {
                        $destination = str_replace($stagingDir . '/restore/', '../../images/uploads/', $filePath);
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