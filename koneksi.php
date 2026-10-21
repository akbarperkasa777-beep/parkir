<?php
declare(strict_types=1);

function db(?string $role = null): PDO
{
    static $connections = [];

    if ($role === null) {
        $role = isset($_SESSION['role']) ? $_SESSION['role'] : 'admin';
    }

    if (!in_array($role, ['admin', 'petugas', 'owner'], true)) {
        throw new InvalidArgumentException('Role database tidak valid.');
    }

    if (isset($connections[$role])) {
        return $connections[$role];
    }

    $host = getenv('DB_HOST') ?: 'localhost';
    $name = getenv('DB_NAME') ?: 'parkir';
    $user = getenv('DB_' . strtoupper($role) . '_USER') ?: (getenv('DB_USER') ?: 'root');
    $password = getenv('DB_' . strtoupper($role) . '_PASSWORD');
    if ($password === false) {
        $password = getenv('DB_PASSWORD') ?: '';
    }

    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';

    try {
        $connections[$role] = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        error_log('Koneksi database gagal: ' . $exception->getMessage());
        throw new RuntimeException('Koneksi database gagal. Periksa konfigurasi database dan impor database.sql.', 0, $exception);
    }

    return $connections[$role];
}
