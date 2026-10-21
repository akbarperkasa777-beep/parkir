<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../koneksi.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $base = preg_replace('~/(admin|petugas|owner)$~', '', $base);
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}

function require_login(): void
{
    if (empty($_SESSION['id_user']) || empty($_SESSION['role'])) {
        redirect('login.php');
    }
}

function require_role($roles): void
{
    require_login();
    $roles = (array) $roles;
    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        exit('403 - Anda tidak memiliki hak akses ke halaman ini.');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if ($submitted === '' || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(400);
        exit('Permintaan tidak valid atau sesi formulir sudah kedaluwarsa. Silakan muat ulang halaman.');
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function log_activity(PDO $pdo, int $userId, string $activity): void
{
    $statement = $pdo->prepare('INSERT INTO tb_log_aktivitas (id_user, aktivitas) VALUES (:id_user, :aktivitas)');
    $statement->execute(['id_user' => $userId, 'aktivitas' => $activity]);
}

function format_rupiah($amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function time_ago(string $datetime): string
{
    $time = strtotime($datetime);
    if (!$time) {
        return $datetime;
    }
    $diff = time() - $time;
    if ($diff < 60) {
        return 'Baru saja';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' menit lalu';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' jam lalu';
    }
    if ($diff < 604800) {
        return floor($diff / 86400) . ' hari lalu';
    }
    return date('d M Y H:i', $time);
}
