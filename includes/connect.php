<?php

require_once __DIR__ . '/database/connection.php';

$databaseFile = wallos_database_path();
$db = wallos_database_connect();

if (!$db) {
    die('Connection to the database failed.');
}

?>