<?php
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'db_ukk';

mysqli_report(MYSQLI_REPORT_OFF); // matikan exception mysqli
$koneksi = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($koneksi->connect_error) {
    die(
        'Koneksi database gagal (errno ' . $koneksi->connect_errno . '): '
        . $koneksi->connect_error
        . '<br><br>'
    );
}

$koneksi->set_charset('utf8mb4');
