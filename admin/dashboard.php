<?php
require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$pdo = db();

// Handle Real-time Live Occupancy API Request
if (isset($_GET['occupancy_live']) && $_GET['occupancy_live'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    try {
        $liveAreas = $pdo->query(
            "SELECT a.id_area, a.nama_area, a.kapasitas, COUNT(t.id_parkir) AS terisi
             FROM tb_area_parkir a
             LEFT JOIN tb_transaksi t ON t.id_area = a.id_area AND t.status = 'masuk'
             GROUP BY a.id_area, a.nama_area, a.kapasitas
             ORDER BY a.nama_area"
        )->fetchAll();

        $activeSlots = $pdo->query(
            "SELECT t.id_parkir, t.id_area, t.waktu_masuk, k.plat_nomor, k.jenis_kendaraan, k.warna
             FROM tb_transaksi t
             JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
             WHERE t.status = 'masuk'
             ORDER BY t.waktu_masuk ASC"
        )->fetchAll();

        $slotsByArea = [];
        foreach ($activeSlots as $slot) {
            $slotsByArea[(int) $slot['id_area']][] = [
                'id_parkir' => (int) $slot['id_parkir'],
                'plat_nomor' => $slot['plat_nomor'],
                'jenis_kendaraan' => $slot['jenis_kendaraan'],
                'warna' => $slot['warna'],
                'waktu_masuk' => date('H:i', strtotime($slot['waktu_masuk'])),
            ];
        }

        $liveCapacity = 0;
        $liveOccupied = 0;
        foreach ($liveAreas as &$liveArea) {
            $areaId = (int) $liveArea['id_area'];
            $areaCapacity = max(0, (int) $liveArea['kapasitas']);
            $liveArea['id_area'] = $areaId;
            $liveArea['kapasitas'] = $areaCapacity;
            $liveArea['terisi'] = count($slotsByArea[$areaId] ?? []);
            $liveArea['vehicles'] = $slotsByArea[$areaId] ?? [];
            $liveCapacity += $areaCapacity;
            $liveOccupied += $liveArea['terisi'];
        }
        unset($liveArea);

        $liveAvailable = max(0, $liveCapacity - $liveOccupied);
        echo json_encode([
            'areas' => $liveAreas,
            'capacity' => $liveCapacity,
            'occupied' => $liveOccupied,
            'available' => $liveAvailable,
            'percent' => $liveCapacity > 0 ? min(100, round(($liveOccupied / $liveCapacity) * 100, 1)) : 0,
            'updated_at' => date('H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('Gagal memperbarui keterisian admin: ' . $exception->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'Status keterisian gagal diperbarui.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Handle Direct Quick CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_transaksi_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID Transaksi', 'Plat Nomor', 'Jenis', 'Area', 'Waktu Masuk', 'Waktu Keluar', 'Durasi (Jam)', 'Biaya Total', 'Status']);
    
    $exportStmt = $pdo->query(
        "SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, a.nama_area, t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total, t.status
         FROM tb_transaksi t
         JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
         JOIN tb_area_parkir a ON a.id_area = t.id_area
         ORDER BY t.id_parkir DESC LIMIT 500"
    );
    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id_parkir'],
            $row['plat_nomor'],
            ucfirst($row['jenis_kendaraan']),
            $row['nama_area'],
            $row['waktu_masuk'],
            $row['waktu_keluar'] ?? '-',
            $row['durasi_jam'] ?? '0',
            $row['biaya_total'] ? 'Rp ' . number_format((float)$row['biaya_total'], 0, ',', '.') : '0',
            strtoupper($row['status']),
        ]);
    }
    fclose($output);
    exit;
}

$today = new DateTimeImmutable('today');
$tomorrow = $today->modify('+1 day');
$chartStart = $today->modify('-6 days');
$todayStart = $today->format('Y-m-d 00:00:00');
$tomorrowStart = $tomorrow->format('Y-m-d 00:00:00');

// Area and occupancy
$areas = $pdo->query(
    "SELECT a.id_area, a.nama_area, a.kapasitas, COUNT(t.id_parkir) AS terisi
     FROM tb_area_parkir a
     LEFT JOIN tb_transaksi t ON t.id_area = a.id_area AND t.status = 'masuk'
     GROUP BY a.id_area, a.nama_area, a.kapasitas
     ORDER BY a.nama_area"
)->fetchAll();

$capacity = 0;
$occupied = 0;
foreach ($areas as $area) {
    $capacity += max(0, (int) $area['kapasitas']);
    $occupied += (int) $area['terisi'];
}
$available = max(0, $capacity - $occupied);
$occupancyPercent = $capacity > 0 ? min(100, round(($occupied / $capacity) * 100, 1)) : 0;

// Transactions today
$todayIn = (int) $pdo->query("SELECT COUNT(*) FROM tb_transaksi WHERE waktu_masuk >= '$todayStart' AND waktu_masuk < '$tomorrowStart'")->fetchColumn();
$todayOut = (int) $pdo->query("SELECT COUNT(*) FROM tb_transaksi WHERE status = 'keluar' AND waktu_keluar >= '$todayStart' AND waktu_keluar < '$tomorrowStart'")->fetchColumn();
$todayRevenueValue = (float) $pdo->query("SELECT COALESCE(SUM(biaya_total), 0) FROM tb_transaksi WHERE status = 'keluar' AND waktu_keluar >= '$todayStart' AND waktu_keluar < '$tomorrowStart'")->fetchColumn();
$activeVehiclesCount = (int) $pdo->query("SELECT COUNT(*) FROM tb_transaksi WHERE status = 'masuk'")->fetchColumn();

// 7 Days Trend Series
$chartDates = [];
$chartLabels = [];
for ($day = $chartStart; $day <= $today; $day = $day->modify('+1 day')) {
    $dateKey = $day->format('Y-m-d');
    $chartDates[$dateKey] = ['transactions' => 0, 'revenue' => 0];
    $chartLabels[] = $day->format('d M');
}

$chartEnd = $tomorrow->format('Y-m-d 00:00:00');
$dailyTx = $pdo->query(
    "SELECT DATE(waktu_masuk) AS tgl, COUNT(*) AS jml 
     FROM tb_transaksi 
     WHERE waktu_masuk >= '{$chartStart->format('Y-m-d 00:00:00')}' AND waktu_masuk < '$chartEnd' 
     GROUP BY DATE(waktu_masuk)"
)->fetchAll();
foreach ($dailyTx as $row) {
    if (isset($chartDates[$row['tgl']])) {
        $chartDates[$row['tgl']]['transactions'] = (int) $row['jml'];
    }
}

$dailyRev = $pdo->query(
    "SELECT DATE(waktu_keluar) AS tgl, COALESCE(SUM(biaya_total), 0) AS rev 
     FROM tb_transaksi 
     WHERE status = 'keluar' AND waktu_keluar >= '{$chartStart->format('Y-m-d 00:00:00')}' AND waktu_keluar < '$chartEnd' 
     GROUP BY DATE(waktu_keluar)"
)->fetchAll();
foreach ($dailyRev as $row) {
    if (isset($chartDates[$row['tgl']])) {
        $chartDates[$row['tgl']]['revenue'] = (float) $row['rev'];
    }
}

$transactionSeries = array_column($chartDates, 'transactions');
$revenueSeries = array_column($chartDates, 'revenue');

// Vehicle Type distribution among currently parked or recent
$vehicleDistribution = $pdo->query(
    "SELECT k.jenis_kendaraan, COUNT(*) AS total
     FROM tb_transaksi t
     JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
     WHERE t.status = 'masuk'
     GROUP BY k.jenis_kendaraan"
)->fetchAll();

$vTypes = ['motor' => 0, 'mobil' => 0, 'lainnya' => 0];
foreach ($vehicleDistribution as $vd) {
    $k = strtolower($vd['jenis_kendaraan']);
    if (isset($vTypes[$k])) {
        $vTypes[$k] = (int) $vd['total'];
    } else {
        $vTypes['lainnya'] += (int) $vd['total'];
    }
}

// Active vehicles for the live parking grid
$activeVehiclesList = $pdo->query(
    "SELECT t.id_parkir, t.id_area, t.waktu_masuk, k.plat_nomor, k.jenis_kendaraan, k.warna, a.nama_area
     FROM tb_transaksi t
     JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
     JOIN tb_area_parkir a ON a.id_area = t.id_area
     WHERE t.status = 'masuk'
     ORDER BY t.id_parkir DESC"
)->fetchAll();

$vehiclesByArea = [];
foreach ($activeVehiclesList as $av) {
    $vehiclesByArea[(int) $av['id_area']][] = $av;
}
foreach ($areas as &$aItem) {
    $aItem['kendaraan_aktif'] = $vehiclesByArea[(int) $aItem['id_area']] ?? [];
}
unset($aItem);

$recentTransactions = $pdo->query(
    "SELECT t.id_parkir, t.waktu_masuk, t.waktu_keluar, t.status, t.biaya_total,
            k.plat_nomor, k.jenis_kendaraan, a.nama_area
     FROM tb_transaksi t
     JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
     JOIN tb_area_parkir a ON a.id_area = t.id_area
     ORDER BY t.id_parkir DESC
     LIMIT 7"
)->fetchAll();

$recentLogs = $pdo->query(
    "SELECT l.id_log, l.aktivitas, l.waktu_aktivitas, u.nama_lengkap
     FROM tb_log_aktivitas l
     JOIN tb_user u ON u.id_user = l.id_user
     ORDER BY l.id_log DESC
     LIMIT 5"
)->fetchAll();

$pageTitle = 'Dashboard Administrator';
require __DIR__ . '/../includes/header.php';
?>

<!-- HERO WELCOME BANNER -->
<section class="welcome-hero-banner">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle fw-semibold px-3 py-1 mb-2">
                <i class="bi bi-shield-check me-1"></i>KONTROL PENUH ADMINISTRATOR
            </span>
            <h1>Selamat Datang, <?= e($_SESSION['nama_lengkap']) ?> 👋</h1>
            <p>Pantau kapasitas slot, status transaksi real-time, dan performa bisnis harian.</p>
        </div>
        <div class="hero-action-buttons no-print">
            <a href="<?= e(app_url('admin/dashboard.php?export=csv')) ?>" class="btn btn-outline-light border-opacity-25 d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-excel"></i><span>Export CSV</span>
            </a>
            <button type="button" class="btn btn-primary d-flex align-items-center gap-2" onclick="window.print()">
                <i class="bi bi-printer"></i><span>Cetak Dashboard</span>
            </button>
        </div>
    </div>
</section>

<!-- 4 STAT CARDS WITH SPARKLINES -->
<div class="row g-3 mb-4">
    <!-- Stat 1: Pendapatan Hari Ini -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern">
            <div class="stat-header">
                <div>
                    <span class="stat-kicker">Pendapatan Hari Ini</span>
                    <div class="stat-number text-danger"><?= format_rupiah($todayRevenueValue) ?></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-red">
                    <i class="bi bi-wallet2"></i>
                </div>
            </div>
            <div class="stat-footer">
                <span class="stat-badge stat-badge-positive">
                    <i class="bi bi-check-circle-fill"></i> Transaksi Keluar
                </span>
                <canvas id="sparklineRev" class="stat-sparkline" width="80" height="28"></canvas>
            </div>
        </div>
    </div>

    <!-- Stat 2: Total Transaksi Hari Ini -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern">
            <div class="stat-header">
                <div>
                    <span class="stat-kicker">Transaksi Hari Ini</span>
                    <div class="stat-number text-dark"><?= number_format($todayIn + $todayOut, 0, ',', '.') ?> <span class="fs-6 text-muted fw-normal">unit</span></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-sky">
                    <i class="bi bi-arrow-left-right"></i>
                </div>
            </div>
            <div class="stat-footer">
                <span class="stat-badge stat-badge-neutral">
                    <i class="bi bi-box-arrow-in-down"></i> Masuk: <?= $todayIn ?> | Keluar: <?= $todayOut ?>
                </span>
                <canvas id="sparklineTx" class="stat-sparkline" width="80" height="28"></canvas>
            </div>
        </div>
    </div>

    <!-- Stat 3: Slot Terisi vs Kosong -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern">
            <div class="stat-header">
                <div>
                    <span class="stat-kicker">Status Keterisian</span>
                    <div class="stat-number text-emerald"><?= $occupied ?> <span class="fs-6 text-muted fw-normal">/ <?= $capacity ?> Slot</span></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-emerald">
                    <i class="bi bi-p-square-fill"></i>
                </div>
            </div>
            <div class="stat-footer">
                <span class="stat-badge stat-badge-positive">
                    <i class="bi bi-lightning-charge-fill"></i> <?= $occupancyPercent ?>% Terpakai (Sisa <?= $available ?>)
                </span>
                <div class="progress w-25" style="height: 6px;">
                    <div class="progress-bar bg-emerald" style="width: <?= $occupancyPercent ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat 4: Kendaraan Parkir Aktif -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern">
            <div class="stat-header">
                <div>
                    <span class="stat-kicker">Parkir Aktif Saat Ini</span>
                    <div class="stat-number text-warning"><?= $activeVehiclesCount ?> <span class="fs-6 text-muted fw-normal">Kendaraan</span></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-amber">
                    <i class="bi bi-car-front-fill"></i>
                </div>
            </div>
            <div class="stat-footer">
                <span class="stat-badge stat-badge-neutral">
                    <span class="pulse-indicator pulse-emerald"></span> Sedang di Area
                </span>
                <span class="small text-muted fw-semibold">Real-Time</span>
            </div>
        </div>
    </div>
</div>

<!-- QUICK ACTIONS WIDGET -->
<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body p-3">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-uppercase extra-small fw-bold text-muted letter-spacing-1">
                <i class="bi bi-lightning-fill text-warning me-1"></i>Pintasan Kontrol Admin (Quick Actions)
            </span>
            <span class="badge bg-light text-secondary border">Aksi Cepat</span>
        </div>
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <a href="<?= e(app_url('admin/user.php')) ?>" class="quick-action-btn">
                    <div class="quick-action-icon bg-danger-subtle text-danger">
                        <i class="bi bi-person-plus-fill"></i>
                    </div>
                    <div>
                        <div class="lh-1">Tambah User</div>
                        <small class="text-muted fw-normal extra-small">Kelola akun staf</small>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="<?= e(app_url('admin/tarif.php')) ?>" class="quick-action-btn">
                    <div class="quick-action-icon bg-success-subtle text-success">
                        <i class="bi bi-currency-exchange"></i>
                    </div>
                    <div>
                        <div class="lh-1">Atur Tarif</div>
                        <small class="text-muted fw-normal extra-small">Biaya per kendaraan</small>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <button type="button" class="quick-action-btn" onclick="window.print()">
                    <div class="quick-action-icon bg-primary-subtle text-primary">
                        <i class="bi bi-printer-fill"></i>
                    </div>
                    <div>
                        <div class="lh-1">Cetak Laporan</div>
                        <small class="text-muted fw-normal extra-small">Ringkasan bulanan</small>
                    </div>
                </button>
            </div>
            <div class="col-6 col-md-3">
                <a href="<?= e(app_url('admin/dashboard.php?export=csv')) ?>" class="quick-action-btn">
                    <div class="quick-action-icon bg-warning-subtle text-warning">
                        <i class="bi bi-file-earmark-spreadsheet-fill"></i>
                    </div>
                    <div>
                        <div class="lh-1">Export Excel/CSV</div>
                        <small class="text-muted fw-normal extra-small">Unduh data instan</small>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- CHARTS SECTION: LINE CHART + DOUGHNUT -->
<div class="row g-3 mb-4">
    <!-- Line Chart: Tren Pendapatan & Transaksi 7 Hari -->
    <div class="col-xl-8">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 border-0 pb-0">
                <div>
                    <h2 class="fs-6 fw-bold text-dark m-0">Tren Pendapatan & Volume Transaksi</h2>
                    <p class="text-muted extra-small m-0">Aktivitas 7 hari terakhir (Berdasarkan waktu masuk & keluar)</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light text-secondary border small">
                        <i class="bi bi-calendar3 me-1"></i><?= e($chartStart->format('d M')) ?> – <?= e($today->format('d M Y')) ?>
                    </span>
                </div>
            </div>
            <div class="card-body pt-3">
                <div style="position: relative; height: 280px; width: 100%;">
                    <canvas id="adminLineChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Doughnut Chart: Keterisian per Jenis Kendaraan -->
    <div class="col-xl-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header border-0 pb-0">
                <h2 class="fs-6 fw-bold text-dark m-0">Distribusi Jenis Kendaraan</h2>
                <p class="text-muted extra-small m-0">Persentase kendaraan yang sedang terparkir</p>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
                <div style="position: relative; height: 210px; width: 100%;">
                    <canvas id="adminDoughnutChart"></canvas>
                </div>
                <div class="d-flex justify-content-around text-center pt-2 border-top">
                    <div>
                        <span class="d-block extra-small text-muted fw-bold">MOTOR</span>
                        <strong class="text-danger fs-6"><?= $vTypes['motor'] ?></strong>
                    </div>
                    <div>
                        <span class="d-block extra-small text-muted fw-bold">MOBIL</span>
                        <strong class="text-primary fs-6"><?= $vTypes['mobil'] ?></strong>
                    </div>
                    <div>
                        <span class="d-block extra-small text-muted fw-bold">LAINNYA</span>
                        <strong class="text-success fs-6"><?= $vTypes['lainnya'] ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Live parking slot monitoring -->
<div class="card border-0 shadow-sm mb-4" style="border-radius: 24px; overflow: hidden;">
    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom p-3">
        <div class="d-flex align-items-center gap-2">
            <span class="pulse-indicator pulse-emerald"></span>
            <div>
                <h2 class="fs-6 fw-bold text-dark m-0">Denah Slot Parkir Real-Time</h2>
                <p class="text-muted extra-small m-0">Visualisasi status slot terisi (<span class="text-danger fw-bold">Merah</span>) dan kosong (<span class="text-emerald fw-bold">Hijau</span>) yang terhubung dinamis ke database</p>
            </div>
        </div>
    </div>
    <div class="p-3 bg-light">
        <div id="adminDashboardParkingGrid"></div>
    </div>
</div>

<!-- RECENT TRANSACTIONS TABLE & AUDIT LOG -->
<div class="row g-3">
    <!-- Clean Recent Transactions Table -->
    <div class="col-xl-8">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center justify-content-between border-bottom">
                <div>
                    <h2 class="fs-6 fw-bold text-dark m-0">Aktivitas Transaksi Terbaru</h2>
                    <p class="text-muted extra-small m-0">Kendaraan yang baru saja masuk atau keluar dari area</p>
                </div>
                <span class="badge bg-light text-secondary border">Real-Time Log</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Plat Nomor</th>
                            <th>Area</th>
                            <th>Waktu Transaksi</th>
                            <th>Biaya</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentTransactions): ?>
                            <?php foreach ($recentTransactions as $tx): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="stat-icon-wrapper p-1 rounded-circle" style="width: 32px; height: 32px; font-size: 0.95rem; background: #f1f5f9;">
                                                <i class="bi <?= strtolower($tx['jenis_kendaraan']) === 'motor' ? 'bi-bicycle' : 'bi-car-front-fill' ?> text-secondary"></i>
                                            </div>
                                            <div>
                                                <strong class="d-block text-dark font-monospace"><?= e($tx['plat_nomor']) ?></strong>
                                                <small class="text-muted extra-small text-uppercase"><?= e($tx['jenis_kendaraan']) ?> &bull; #<?= (int)$tx['id_parkir'] ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= e($tx['nama_area']) ?></span></td>
                                    <td class="text-nowrap small text-muted">
                                        <?= e(date('d M, H:i', strtotime($tx['status'] === 'keluar' ? $tx['waktu_keluar'] : $tx['waktu_masuk']))) ?>
                                    </td>
                                    <td class="fw-bold text-dark">
                                        <?= $tx['biaya_total'] ? format_rupiah($tx['biaya_total']) : '<span class="text-muted fw-normal">-</span>' ?>
                                    </td>
                                    <td>
                                        <?php if ($tx['status'] === 'masuk'): ?>
                                            <span class="badge badge-masuk"><i class="bi bi-box-arrow-in-down me-1"></i>Aktif / Masuk</span>
                                        <?php else: ?>
                                            <span class="badge badge-keluar"><i class="bi bi-check2-circle me-1"></i>Selesai</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">Belum ada transaksi parkir.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Audit Activity Timeline -->
    <div class="col-xl-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center justify-content-between border-bottom">
                <h2 class="fs-6 fw-bold text-dark m-0">Jejak Audit Sistem</h2>
                <a href="<?= e(app_url('admin/log.php')) ?>" class="text-danger text-decoration-none extra-small fw-bold">Semua Log &rarr;</a>
            </div>
            <div class="card-body p-3">
                <div class="d-flex flex-column gap-3">
                    <?php if ($recentLogs): ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <div class="d-flex align-items-start gap-2 border-bottom pb-2">
                                <div class="bg-light rounded-circle p-2 text-danger mt-1">
                                    <i class="bi bi-activity small"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <p class="m-0 small text-dark fw-medium lh-sm"><?= e($log['aktivitas']) ?></p>
                                    <div class="d-flex justify-content-between text-muted extra-small mt-1">
                                        <span><i class="bi bi-person me-1"></i><?= e($log['nama_lengkap']) ?></span>
                                        <span><?= e(time_ago($log['waktu_aktivitas'])) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted small">Belum ada catatan log aktivitas.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts and real-time parking grid -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    // Sparkline Charts
    const sparkTxEl = document.getElementById('sparklineTx');
    if (sparkTxEl) {
        new Chart(sparkTxEl, {
            type: 'line',
            data: {
                labels: [1,2,3,4,5,6,7],
                datasets: [{
                    data: <?= json_encode($transactionSeries) ?>,
                    borderColor: '#0ea5e9',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    pointRadius: 0
                }]
            },
            options: { responsive: false, plugins: { legend: { display: false }, tooltip: { enabled: false } }, scales: { x: { display: false }, y: { display: false } } }
        });
    }

    const sparkRevEl = document.getElementById('sparklineRev');
    if (sparkRevEl) {
        new Chart(sparkRevEl, {
            type: 'line',
            data: {
                labels: [1,2,3,4,5,6,7],
                datasets: [{
                    data: <?= json_encode($revenueSeries) ?>,
                    borderColor: '#e50914',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    pointRadius: 0
                }]
            },
            options: { responsive: false, plugins: { legend: { display: false }, tooltip: { enabled: false } }, scales: { x: { display: false }, y: { display: false } } }
        });
    }

    // Main Line Chart: Trends
    const lineCtx = document.getElementById('adminLineChart');
    if (lineCtx) {
        const ctx = lineCtx.getContext('2d');
        const gradRev = ctx.createLinearGradient(0, 0, 0, 260);
        gradRev.addColorStop(0, 'rgba(229, 9, 20, 0.25)');
        gradRev.addColorStop(1, 'rgba(229, 9, 20, 0.01)');

        const gradTx = ctx.createLinearGradient(0, 0, 0, 260);
        gradTx.addColorStop(0, 'rgba(14, 165, 233, 0.2)');
        gradTx.addColorStop(1, 'rgba(14, 165, 233, 0.01)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [
                    {
                        label: 'Pendapatan (Rp)',
                        data: <?= json_encode($revenueSeries) ?>,
                        borderColor: '#e50914',
                        backgroundColor: gradRev,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.35,
                        yAxisID: 'yRev',
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#e50914',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7
                    },
                    {
                        label: 'Volume Transaksi',
                        data: <?= json_encode($transactionSeries) ?>,
                        borderColor: '#0ea5e9',
                        backgroundColor: gradTx,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.35,
                        yAxisID: 'yTx',
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#0ea5e9',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true, font: { family: 'Plus Jakarta Sans', weight: '600' } } },
                    tooltip: {
                        callbacks: {
                            label: function(c) {
                                if (c.datasetIndex === 0) return ' Pendapatan: Rp ' + c.raw.toLocaleString('id-ID');
                                return ' Transaksi: ' + c.raw + ' Unit';
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    yRev: {
                        type: 'linear', position: 'left',
                        ticks: { callback: v => 'Rp ' + (v >= 1000 ? (v/1000) + 'k' : v) },
                        grid: { color: 'rgba(226, 232, 240, 0.6)' }
                    },
                    yTx: {
                        type: 'linear', position: 'right',
                        ticks: { stepSize: 1 },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // Doughnut Chart: Vehicle Types
    const doughnutCtx = document.getElementById('adminDoughnutChart');
    if (doughnutCtx) {
        new Chart(doughnutCtx, {
            type: 'doughnut',
            data: {
                labels: ['Motor', 'Mobil', 'Lainnya'],
                datasets: [{
                    data: [<?= $vTypes['motor'] ?>, <?= $vTypes['mobil'] ?>, <?= $vTypes['lainnya'] ?>],
                    backgroundColor: ['#e50914', '#0ea5e9', '#10b981'],
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, font: { family: 'Plus Jakarta Sans', weight: '600' } } }
                }
            }
        });
    }
})();
</script>
<script src="<?= e(app_url('assets/parking-grid.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const areasData = <?= json_encode($areas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const visualizer = new ParkingGridVisualizer('adminDashboardParkingGrid', {
        pollUrl: 'area.php?live=1',
        pollInterval: 8000,
        showRefreshButton: true
    });
    visualizer.setData(areasData);
    visualizer.startPolling();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
