<?php
require_once __DIR__ . '/includes/app.php';

$isAdmin = !empty($_SESSION['id_user']) && ($_SESSION['role'] ?? '') === 'admin';
if (!empty($_SESSION['id_user']) && !$isAdmin) {
    redirect('index.php');
}

$errors = [];
$fullName = '';
$username = '';
$role = 'petugas';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fullName = trim((string) ($_POST['nama_lengkap'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $requestedRole = (string) ($_POST['role'] ?? '');

    $allowedRoles = $isAdmin ? ['admin', 'petugas', 'owner'] : ['petugas', 'owner'];
    if ($fullName === '' || strlen($fullName) > 50) {
        $errors[] = 'Nama lengkap wajib diisi dan maksimal 50 karakter.';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        $errors[] = 'Username harus terdiri dari 3-50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.';
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        $errors[] = 'Password harus terdiri dari 8-72 karakter.';
    }
    if (!in_array($requestedRole, $allowedRoles, true)) {
        $errors[] = 'Role yang dipilih tidak diizinkan.';
    } else {
        $role = $requestedRole;
    }

    if (!$errors) {
        try {
            $pdo = db('admin');
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO tb_user (nama_lengkap, username, password, role, status_aktif) VALUES (:nama_lengkap, :username, :password, :role, :status_aktif)');
            $statement->execute([
                'nama_lengkap' => $fullName,
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'status_aktif' => $isAdmin ? 1 : 0,
            ]);

            if ($isAdmin) {
                log_activity($pdo, (int) $_SESSION['id_user'], 'Tambah user #' . (int) $pdo->lastInsertId() . ' melalui halaman pendaftaran');
                $pdo->commit();
                set_flash('success', 'Akun berhasil dibuat dan dapat digunakan untuk login.');
                redirect('admin/user.php');
            }

            $pdo->commit();
            set_flash('success', 'Pendaftaran berhasil. Akun Anda menunggu aktivasi Admin sebelum dapat digunakan untuk login.');
            redirect('login.php');
        } catch (PDOException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Pendaftaran akun gagal: ' . $exception->getMessage());
            if ($exception->getCode() === '23000') {
                $errors[] = 'Username sudah digunakan. Silakan pilih username lain.';
            } else {
                $errors[] = 'Pendaftaran gagal disimpan. Periksa koneksi database dan coba lagi.';
            }
        } catch (RuntimeException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Pendaftaran akun gagal diproses: ' . $exception->getMessage());
            $errors[] = 'Pendaftaran tidak dapat diproses. Periksa koneksi dan konfigurasi database.';
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f7f9">
    <title><?= $isAdmin ? 'Tambah Akun' : 'Daftar Akun' ?> - PARKIR-APP</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(app_url('assets/favicon.svg')) ?>">
    <link rel="alternate icon" href="<?= e(app_url('assets/favicon.svg')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(app_url('assets/auth.css')) ?>" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="register-page">
    <main class="register-card">
        <div class="register-body">
            <a class="register-brand" href="index.php" aria-label="PARKIR-APP, beranda"><i class="bi bi-geo-alt-fill"></i>PARKIR<span>-APP</span></a>
            <div class="register-heading">
                <h1><?= $isAdmin ? 'Buat akun pengguna' : 'Daftar akun baru' ?></h1>
                <p><?= $isAdmin ? 'Tambahkan akun ke sistem parkir.' : 'Buat akun Petugas atau Owner. Akun baru perlu diaktifkan oleh Admin sebelum dapat digunakan.' ?></p>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="post" action="register.php">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="nama_lengkap">Nama Lengkap</label>
                    <input class="form-control" id="nama_lengkap" name="nama_lengkap" maxlength="50" autocomplete="name" value="<?= e($fullName) ?>" placeholder="Masukkan nama lengkap" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <input class="form-control" id="username" name="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}" autocomplete="username" value="<?= e($username) ?>" placeholder="Pilih username" required>
                    <div class="form-text">3-50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="Minimal 8 karakter" required>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="role">Role akun</label>
                    <select class="form-select" id="role" name="role" required>
                        <option value="petugas" <?= $role === 'petugas' ? 'selected' : '' ?>>Petugas</option>
                        <option value="owner" <?= $role === 'owner' ? 'selected' : '' ?>>Owner</option>
                        <?php if ($isAdmin): ?><option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option><?php endif; ?>
                    </select>
                    <?php if (!$isAdmin): ?><div class="form-text">Pembuatan akun Admin hanya dapat dilakukan melalui akses Admin yang sudah login.</div><?php endif; ?>
                </div>
                <button class="btn btn-register w-100" type="submit"><i class="bi bi-person-plus me-2"></i><?= $isAdmin ? 'Buat akun' : 'Daftar sekarang' ?></button>
            </form>
            <div class="register-footer">Sudah punya akun? <a href="login.php">Masuk di sini</a></div>
        </div>
    </main>
</body>
</html>
