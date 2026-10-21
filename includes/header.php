<?php
$pageTitle = isset($pageTitle) ? $pageTitle : 'Sistem Informasi Parkir';
$flash = take_flash();
$role = $_SESSION['role'] ?? '';
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$navigation = [
    'admin' => [
        ['label' => 'Dashboard', 'path' => 'admin/dashboard.php', 'icon' => 'bi-grid-1x2-fill'],
        ['label' => 'Data User', 'path' => 'admin/user.php', 'icon' => 'bi-people-fill'],
        ['label' => 'Tarif Parkir', 'path' => 'admin/tarif.php', 'icon' => 'bi-receipt'],
        ['label' => 'Area Parkir', 'path' => 'admin/area.php', 'icon' => 'bi-p-square-fill'],
        ['label' => 'Kendaraan', 'path' => 'admin/kendaraan.php', 'icon' => 'bi-car-front-fill'],
        ['label' => 'Backup & Restore', 'path' => 'admin/backup_restore.php', 'icon' => 'bi-database-gear'],
        ['label' => 'Log Aktivitas', 'path' => 'admin/log.php', 'icon' => 'bi-activity'],
    ],
    'petugas' => [
        ['label' => 'Dashboard', 'path' => 'petugas/dashboard.php', 'icon' => 'bi-grid-1x2-fill'],
        ['label' => 'Parkir Masuk', 'path' => 'petugas/transaksi_masuk.php', 'icon' => 'bi-box-arrow-in-down-left'],
        ['label' => 'Parkir Keluar', 'path' => 'petugas/transaksi_keluar.php', 'icon' => 'bi-box-arrow-up-right'],
        ['label' => 'Riwayat Transaksi', 'path' => 'petugas/riwayat_transaksi.php', 'icon' => 'bi-clock-history'],
    ],
    'owner' => [
        ['label' => 'Dashboard', 'path' => 'owner/dashboard.php', 'icon' => 'bi-grid-1x2-fill'],
        ['label' => 'Rekap Transaksi', 'path' => 'owner/rekap_transaksi.php', 'icon' => 'bi-bar-chart-fill'],
    ],
];
$roleLabels = [
    'admin' => 'Administrator',
    'petugas' => 'Petugas Operasional',
    'owner' => 'Owner Eksekutif',
];
$roleLabel = $roleLabels[$role] ?? 'Pengguna';

// Notifications and active stats
$headerNotifications = [];
$activeParkedCount = 0;
if (!empty($_SESSION['id_user'])) {
    try {
        $headerPdo = db();
        $notifStmt = $headerPdo->query("SELECT l.id_log, l.aktivitas, l.waktu_aktivitas, u.nama_lengkap 
                                        FROM tb_log_aktivitas l 
                                        JOIN tb_user u ON u.id_user = l.id_user 
                                        ORDER BY l.id_log DESC LIMIT 5");
        $headerNotifications = $notifStmt->fetchAll();
        $activeParkedCount = (int) $headerPdo->query("SELECT COUNT(*) FROM tb_transaksi WHERE status = 'masuk'")->fetchColumn();
    } catch (Throwable $t) {
        // Non-blocking
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — SMART PARKING SYSTEM</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(app_url('assets/favicon.svg')) ?>">
    <link rel="alternate icon" href="<?= e(app_url('assets/favicon.svg')) ?>">
    <!-- Google Fonts: Plus Jakarta Sans & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5.3.3 & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Application Main Stylesheet -->
    <link href="<?= e(app_url('assets/style.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <!-- SIDEBAR NAV (DESKTOP) -->
    <aside class="app-sidebar d-none d-lg-flex">
        <a class="brand" href="<?= e(app_url('index.php')) ?>">
            <span class="brand-mark"><i class="bi bi-geo-alt-fill"></i></span>
            <span>
                <strong>PARKIR<span class="brand-dot">-APP</span></strong>
                <small>SMART PARKING SYSTEM</small>
            </span>
        </a>

        <div class="sidebar-section-label">NAVIGASI UTAMA</div>
        <nav class="sidebar-nav" aria-label="Navigasi utama">
            <?php foreach ($navigation[$role] ?? [] as $item): ?>
                <a class="sidebar-link <?= $currentPage === basename($item['path']) ? 'active' : '' ?>" href="<?= e(app_url($item['path'])) ?>">
                    <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <div class="sidebar-live-pill">
                <span class="pulse-indicator pulse-emerald"></span>
                <div class="sidebar-live-text">
                    <strong><?= $activeParkedCount ?> Kendaraan Parkir</strong>
                    <small>Sistem Aktif & Terpantau</small>
                </div>
            </div>
            <div class="sidebar-help">
                <i class="bi bi-shield-check"></i>
                <span><strong>Koneksi Terenkripsi</strong><small>Hak Akses: <?= e($roleLabel) ?></small></span>
            </div>
            <div class="sidebar-version">PARKIR-APP v2.5 • ENTERPRISE</div>
        </div>
    </aside>

    <!-- MAIN APP WRAPPER -->
    <div class="app-main">
        <!-- TOPBAR HEADER -->
        <header class="topbar">
            <!-- Left Side: Mobile Toggle & Page Info -->
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-light mobile-menu-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-label="Buka navigasi">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div class="topbar-heading-wrap">
                    <span class="topbar-eyebrow">
                        <i class="bi bi-layers-half me-1"></i>PARKIR-APP / <?= e(strtoupper($role)) ?>
                    </span>
                    <h1 class="topbar-title m-0"><?= e($pageTitle) ?></h1>
                </div>
            </div>

            <!-- Right Side: Live Clock, Notifications & User Profile -->
            <div class="d-flex align-items-center gap-2 gap-sm-3">
                <!-- LIVE REAL-TIME CLOCK & DATE -->
                <div class="live-clock-widget d-none d-md-flex align-items-center">
                    <div class="clock-icon-bubble">
                        <i class="bi bi-clock-fill"></i>
                        <span class="pulse-indicator pulse-emerald"></span>
                    </div>
                    <div class="clock-text-wrap">
                        <div class="clock-time font-monospace" id="liveClockTime">--:--:-- WIB</div>
                        <div class="clock-date" id="liveClockDate">Memuat tanggal...</div>
                    </div>
                </div>

                <?php if (!empty($_SESSION['id_user'])): ?>
                    <!-- NOTIFICATION CENTER DROPDOWN -->
                    <div class="dropdown notification-dropdown">
                        <button class="btn btn-icon-round" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Pusat Notifikasi">
                            <i class="bi bi-bell-fill"></i>
                            <?php if (!empty($headerNotifications)): ?>
                                <span class="notification-badge-dot"></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end shadow-lg notification-menu border-0 p-0">
                            <div class="notification-header d-flex align-items-center justify-content-between p-3 border-bottom">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-bell-fill text-danger"></i>
                                    <strong class="fs-6">Aktivitas Terkini</strong>
                                </div>
                                <span class="badge rounded-pill bg-danger-subtle text-danger fw-semibold"><?= count($headerNotifications) ?> Baru</span>
                            </div>
                            <div class="notification-list custom-scrollbar">
                                <?php if ($headerNotifications): ?>
                                    <?php foreach ($headerNotifications as $notif): ?>
                                        <div class="notification-item p-3 border-bottom d-flex align-items-start gap-2">
                                            <span class="notif-bullet mt-1"></span>
                                            <div class="flex-grow-1">
                                                <p class="mb-1 text-dark fw-medium small lh-sm"><?= e($notif['aktivitas']) ?></p>
                                                <div class="d-flex align-items-center justify-content-between text-muted extra-small">
                                                    <span><i class="bi bi-person me-1"></i><?= e($notif['nama_lengkap']) ?></span>
                                                    <span><i class="bi bi-clock me-1"></i><?= e(time_ago($notif['waktu_aktivitas'])) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-4 text-center text-muted small">
                                        <i class="bi bi-check2-circle text-success fs-3 d-block mb-1"></i>
                                        Tidak ada aktivitas baru.
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="notification-footer p-2 text-center bg-light rounded-bottom">
                                <?php if ($role === 'admin'): ?>
                                    <a href="<?= e(app_url('admin/log.php')) ?>" class="text-decoration-none small fw-semibold text-danger">Lihat Semua Log Aktivitas <i class="bi bi-arrow-right"></i></a>
                                <?php else: ?>
                                    <span class="text-muted extra-small">Sistem Terhubung Real-Time</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- USER PROFILE DROPDOWN -->
                    <div class="dropdown user-dropdown">
                        <button class="btn user-profile-btn d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-avatar-modern">
                                <span><?= strtoupper(substr($_SESSION['nama_lengkap'] ?? 'U', 0, 1)) ?></span>
                                <span class="status-online"></span>
                            </div>
                            <div class="user-info-text text-start d-none d-sm-block">
                                <span class="user-name-text text-truncate d-block fw-bold"><?= e($_SESSION['nama_lengkap']) ?></span>
                                <span class="user-role-badge badge-<?= e($role) ?>"><?= e($roleLabel) ?></span>
                            </div>
                            <i class="bi bi-chevron-down ms-1 text-muted fs-7"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg user-menu border-0 p-2">
                            <li class="px-3 py-2 border-bottom mb-1">
                                <span class="text-muted extra-small text-uppercase fw-bold letter-spacing-1 d-block mb-1">Akun Masuk</span>
                                <strong class="d-block text-dark small"><?= e($_SESSION['nama_lengkap']) ?></strong>
                                <span class="badge bg-light text-secondary border mt-1 extra-small"><?= e($roleLabel) ?></span>
                            </li>
                            <?php if ($role === 'admin'): ?>
                                <li><a class="dropdown-item rounded-2 py-2" href="<?= e(app_url('admin/user.php')) ?>"><i class="bi bi-person-gear me-2 text-primary"></i>Manajemen User</a></li>
                                <li><a class="dropdown-item rounded-2 py-2" href="<?= e(app_url('admin/tarif.php')) ?>"><i class="bi bi-sliders me-2 text-success"></i>Pengaturan Tarif</a></li>
                            <?php elseif ($role === 'petugas'): ?>
                                <li><a class="dropdown-item rounded-2 py-2" href="<?= e(app_url('petugas/riwayat_transaksi.php')) ?>"><i class="bi bi-receipt-cutoff me-2 text-primary"></i>Riwayat Shift Saya</a></li>
                            <?php elseif ($role === 'owner'): ?>
                                <li><a class="dropdown-item rounded-2 py-2" href="<?= e(app_url('owner/rekap_transaksi.php')) ?>"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Laporan Keuangan</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li>
                                <form method="post" action="<?= e(app_url('logout.php')) ?>" class="m-0">
                                    <?= csrf_field() ?>
                                    <button class="dropdown-item rounded-2 py-2 text-danger fw-semibold" type="submit">
                                        <i class="bi bi-box-arrow-right me-2"></i>Keluar dari Sistem
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <!-- OFFCANVAS FOR MOBILE DEVICES -->
        <div class="offcanvas offcanvas-start mobile-sidebar" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
            <div class="offcanvas-header border-bottom border-secondary border-opacity-25 pb-3">
                <a class="brand" href="<?= e(app_url('index.php')) ?>" id="mobileNavLabel">
                    <span class="brand-mark"><i class="bi bi-geo-alt-fill"></i></span>
                    <span><strong>PARKIR<span class="brand-dot">-APP</span></strong><small>SMART PARKING SYSTEM</small></span>
                </a>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
            </div>
            <div class="offcanvas-body">
                <div class="mobile-clock-bar mb-3 p-2 rounded-3 bg-dark bg-opacity-50 text-white small d-flex align-items-center justify-content-between">
                    <span id="mobileClockDate">--</span>
                    <strong class="text-warning font-monospace" id="mobileClockTime">--:--:--</strong>
                </div>
                <div class="sidebar-section-label">MENU UTAMA</div>
                <nav class="sidebar-nav" aria-label="Navigasi seluler">
                    <?php foreach ($navigation[$role] ?? [] as $item): ?>
                        <a class="sidebar-link <?= $currentPage === basename($item['path']) ? 'active' : '' ?>" href="<?= e(app_url($item['path'])) ?>">
                            <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i><span><?= e($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="mt-4 pt-3 border-top border-secondary border-opacity-25">
                    <form method="post" action="<?= e(app_url('logout.php')) ?>" class="m-0">
                        <?= csrf_field() ?>
                        <button class="btn btn-danger w-100 py-2 d-flex align-items-center justify-content-center gap-2" type="submit">
                            <i class="bi bi-box-arrow-right"></i><span>Keluar Akun</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- MAIN PAGE CONTENT CONTAINER -->
        <main class="page-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-modern alert-dismissible fade show shadow-sm" role="alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-5"></i>
                        <div><?= e($flash['message']) ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
                </div>
            <?php endif; ?>
