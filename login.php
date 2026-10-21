<?php
require_once __DIR__ . '/includes/app.php';

if (!empty($_SESSION['id_user'])) {
    redirect('index.php');
}

$flash = take_flash();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        try {
            $pdo = db('admin');
            $statement = $pdo->prepare('SELECT id_user, nama_lengkap, username, password, role, status_aktif FROM tb_user WHERE username = :username LIMIT 1');
            $statement->execute(['username' => $username]);
            $user = $statement->fetch();

            $validPassword = false;
            if ($user && preg_match('/^[a-f0-9]{64}$/i', $user['password'])) {
                $validPassword = hash_equals(strtolower($user['password']), hash('sha256', $password));
            } elseif ($user) {
                $validPassword = password_verify($password, $user['password']);
            }

            if (!$user || (int) $user['status_aktif'] !== 1 || !$validPassword) {
                $error = 'Username atau password salah, atau akun dinonaktifkan.';
            } else {
                if (preg_match('/^[a-f0-9]{64}$/i', $user['password']) || password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $updatePassword = $pdo->prepare('UPDATE tb_user SET password = :password WHERE id_user = :id_user');
                    $updatePassword->execute([
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'id_user' => $user['id_user'],
                    ]);
                }
                log_activity($pdo, (int) $user['id_user'], 'Login ke sistem');
                session_regenerate_id(true);
                $_SESSION['id_user'] = (int) $user['id_user'];
                $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                $_SESSION['role'] = $user['role'];
                redirect('index.php');
            }
        } catch (RuntimeException $exception) {
            error_log('Login gagal diproses: ' . $exception->getMessage());
            $error = 'Login tidak dapat diproses. Periksa koneksi dan konfigurasi database MySQL.';
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk Sistem — SMART PARKING SYSTEM</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(app_url('assets/favicon.svg')) ?>">
    <link rel="alternate icon" href="<?= e(app_url('assets/favicon.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(app_url('assets/style.css')) ?>" rel="stylesheet">
    <link href="<?= e(app_url('assets/auth.css')) ?>" rel="stylesheet">
</head>
<body class="login-page">
<main class="login-card shadow-lg">
    <div class="card-body">
        <div class="login-brand">
            <div class="brand-mark">
                <i class="bi bi-geo-alt-fill"></i>
            </div>
            <h1>PARKIR-APP</h1>
            <p>Smart Parking Management & Gate Automation</p>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-modern py-2 px-3 small mb-3" role="status">
                <i class="bi bi-info-circle-fill me-1"></i><?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger alert-modern py-2 px-3 small mb-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- Quick Demo Switcher Pills -->
        <div class="demo-pills-wrap">
            <span class="demo-pills-label"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Akun Cepat (Klik untuk isi):</span>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="demo-btn-pill" onclick="fillLogin('admin', 'admin123')">
                    <i class="bi bi-shield-lock me-1 text-danger"></i>Admin
                </button>
                <button type="button" class="demo-btn-pill" onclick="fillLogin('petugas', 'petugas123')">
                    <i class="bi bi-person-badge me-1 text-primary"></i>Petugas
                </button>
                <button type="button" class="demo-btn-pill" onclick="fillLogin('owner', 'owner123')">
                    <i class="bi bi-briefcase me-1 text-success"></i>Owner
                </button>
            </div>
        </div>

        <form method="post" id="loginForm">
            <?= csrf_field() ?>
            <div class="form-floating-custom">
                <input class="form-control" id="username" name="username" maxlength="50" autocomplete="username" placeholder="Username" required autofocus>
                <i class="bi bi-person input-icon-left"></i>
            </div>
            
            <div class="form-floating-custom">
                <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" placeholder="Password" required>
                <i class="bi bi-key input-icon-left"></i>
                <button type="button" class="btn-toggle-password" id="togglePasswordBtn" aria-label="Tampilkan Password">
                    <i class="bi bi-eye" id="togglePasswordIcon"></i>
                </button>
            </div>

            <button class="btn btn-login-submit w-100 mt-2" type="submit">
                <i class="bi bi-box-arrow-in-right me-2"></i>Masuk ke Sistem
            </button>
        </form>

        <div class="login-footer-links">
            <a class="login-back-home" href="<?= e(app_url('index.php')) ?>">
                <i class="bi bi-arrow-left"></i>
                <span>Kembali ke Halaman Depan</span>
            </a>
        </div>
    </div>
</main>

<script>
    // Password toggle
    const toggleBtn = document.getElementById('togglePasswordBtn');
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('togglePasswordIcon');
    if (toggleBtn && passwordInput && toggleIcon) {
        toggleBtn.addEventListener('click', function() {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            toggleIcon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    }

    // Quick demo filler
    function fillLogin(u, p) {
        document.getElementById('username').value = u;
        document.getElementById('password').value = p;
        document.getElementById('password').focus();
    }
</script>
</body>
</html>
