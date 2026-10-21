<?php
require_once __DIR__ . '/../includes/app.php';
require_role('owner');

$pdo = db();

// Date range filters
$today = new DateTimeImmutable('today');
$monthStart = $today->modify('first day of this month');
$defaultStartDate = $monthStart->format('Y-m-d');
$defaultEndDate = $today->format('Y-m-d');

$startDate = trim((string) ($_GET['mulai'] ?? $defaultStartDate));
$endDate = trim((string) ($_GET['sampai'] ?? $defaultEndDate));

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = $defaultStartDate;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = $defaultEndDate;
}
if ($startDate > $endDate) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

$startDateTime = $startDate . ' 00:00:00';
$endDateTime = $endDate . ' 23:59:59';

// Handle Direct CSV Export for Owner
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_eksekutif_' . $startDate . '_sd_' . $endDate . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID Tiket', 'Plat Nomor', 'Jenis Kendaraan', 'Area Parkir', 'Waktu Masuk', 'Waktu Keluar', 'Durasi (Jam)', 'Total Biaya', 'Status']);

    $stmt = $pdo->prepare(
        "SELECT id_parkir, plat_nomor, jenis_kendaraan, nama_area, waktu_masuk, waktu_keluar, durasi_jam, biaya_total, status
         FROM v_rekap_transaksi
         WHERE waktu_masuk >= :st AND waktu_masuk <= :en
         ORDER BY id_parkir DESC"
    );
    $stmt->execute(['st' => $startDateTime, 'en' => $endDateTime]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['id_parkir'],
            $r['plat_nomor'],
            ucfirst($r['jenis_kendaraan']),
            $r['nama_area'],
            $r['waktu_masuk'],
            $r['waktu_keluar'] ?? '-',
            $r['durasi_jam'] ?? '0',
            $r['biaya_total'] ? 'Rp ' . number_format((float)$r['biaya_total'], 0, ',', '.') : '0',
            strtoupper($r['status'])
        ]);
    }
    fclose($out);
    exit;
}

// 1. Total Omzet & Transactions in Filtered Period
$financialSummary = $pdo->prepare(
    "SELECT 
        COALESCE(SUM(CASE WHEN status = 'keluar' THEN biaya_total ELSE 0 END), 0) AS total_omzet,
        COUNT(*) AS total_transaksi,
        SUM(CASE WHEN status = 'keluar' THEN 1 ELSE 0 END) AS total_selesai,
        AVG(CASE WHEN status = 'keluar' AND durasi_jam IS NOT NULL THEN durasi_jam ELSE NULL END) AS avg_duration_hours
     FROM v_rekap_transaksi
     WHERE waktu_masuk >= :st AND waktu_masuk <= :en"
);
$financialSummary->execute(['st' => $startDateTime, 'en' => $endDateTime]);
$finance = $financialSummary->fetch();
$totalOmzet = (float) $finance['total_omzet'];
$totalTx = (int) $finance['total_transaksi'];
$totalSelesai = (int) $finance['total_selesai'];
$avgStayHours = round((float) ($finance['avg_duration_hours'] ?? 0), 1);

// Previous Period Comparison (MoM)
$prevStartDate = date('Y-m-d 00:00:00', strtotime($startDate . ' -1 month'));
$prevEndDate = date('Y-m-d 23:59:59', strtotime($endDate . ' -1 month'));
$prevRevenueStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(biaya_total), 0) FROM v_rekap_transaksi 
     WHERE status = 'keluar' AND waktu_keluar >= :pst AND waktu_keluar <= :pen"
);
$prevRevenueStmt->execute(['pst' => $prevStartDate, 'pen' => $prevEndDate]);
$prevOmzet = (float) $prevRevenueStmt->fetchColumn();

$momPercent = null;
if ($prevOmzet > 0) {
    $momPercent = round((($totalOmzet - $prevOmzet) / $prevOmzet) * 100, 1);
}

// Projection for end of month
$daysInMonth = (int) $today->format('t');
$dayOfMonth = max(1, (int) $today->format('j'));
$runRateDaily = $totalOmzet / $dayOfMonth;
$projectedRevenue = $runRateDaily * $daysInMonth;

// Peak Hours Query
$peakHourQuery = $pdo->prepare(
    "SELECT HOUR(waktu_masuk) AS jam, COUNT(*) AS count_in
     FROM v_rekap_transaksi
     WHERE waktu_masuk >= :st AND waktu_masuk <= :en
     GROUP BY HOUR(waktu_masuk)
     ORDER BY count_in DESC LIMIT 1"
);
$peakHourQuery->execute(['st' => $startDateTime, 'en' => $endDateTime]);
$peakRow = $peakHourQuery->fetch();
$peakHourText = $peakRow ? sprintf('%02d:00 - %02d:00 (%d Kendaraan)', $peakRow['jam'], ($peakRow['jam']+1)%24, $peakRow['count_in']) : 'Belum Terdeteksi';

// 2. Daily Revenue Trend for Chart
$dailyTrendStmt = $pdo->prepare(
    "SELECT DATE(waktu_keluar) AS tgl, COALESCE(SUM(biaya_total), 0) AS daily_rev, COUNT(*) AS daily_cnt
     FROM v_rekap_transaksi
     WHERE status = 'keluar' AND waktu_keluar >= :st AND waktu_keluar <= :en
     GROUP BY DATE(waktu_keluar)
     ORDER BY tgl ASC"
);
$dailyTrendStmt->execute(['st' => $startDateTime, 'en' => $endDateTime]);
$dailyTrendRows = $dailyTrendStmt->fetchAll();

$trendDates = [];
$trendRev = [];
$trendCnt = [];
foreach ($dailyTrendRows as $d) {
    $trendDates[] = date('d M', strtotime($d['tgl']));
    $trendRev[] = (float) $d['daily_rev'];
    $trendCnt[] = (int) $d['daily_cnt'];
}
if (empty($trendDates)) {
    $trendDates = [date('d M')];
    $trendRev = [0];
    $trendCnt = [0];
}

// 3. Comparison Revenue by Area
$areaStmt = $pdo->prepare(
    "SELECT nama_area, 
            COUNT(*) AS total_transaksi,
            COALESCE(SUM(CASE WHEN status = 'keluar' THEN biaya_total ELSE 0 END), 0) AS omzet
     FROM v_rekap_transaksi
     WHERE waktu_masuk >= :st AND waktu_masuk <= :en
     GROUP BY nama_area
     ORDER BY omzet DESC"
);
$areaStmt->execute(['st' => $startDateTime, 'en' => $endDateTime]);
$areaRows = $areaStmt->fetchAll();

$areaLabels = [];
$areaValues = [];
foreach ($areaRows as $ar) {
    $areaLabels[] = $ar['nama_area'];
    $areaValues[] = (float) $ar['omzet'];
}

// 4. Vehicle Type Breakdown
$typeStmt = $pdo->prepare(
    "SELECT jenis_kendaraan,
            COUNT(*) AS total_unit,
            COALESCE(SUM(CASE WHEN status = 'keluar' THEN biaya_total ELSE 0 END), 0) AS omzet
     FROM v_rekap_transaksi
     WHERE waktu_masuk >= :st AND waktu_masuk <= :en
     GROUP BY jenis_kendaraan
     ORDER BY omzet DESC"
);
$typeStmt->execute(['st' => $startDateTime, 'en' => $endDateTime]);
$typeRows = $typeStmt->fetchAll();

// 5. 24-Hour Peak Hours Distribution
$hourDistribution = array_fill(0, 24, 0);
$hourStmt = $pdo->prepare(
    "SELECT HOUR(waktu_masuk) AS jam, COUNT(*) AS total
     FROM v_rekap_transaksi
     WHERE waktu_masuk >= :st AND waktu_masuk <= :en
     GROUP BY HOUR(waktu_masuk)
     ORDER BY jam ASC"
);
$hourStmt->execute(['st' => $startDateTime, 'en' => $endDateTime]);
foreach ($hourStmt->fetchAll() as $hr) {
    $hourDistribution[(int)$hr['jam']] = (int)$hr['total'];
}
$hourLabels = [];
for ($h = 0; $h < 24; $h++) {
    $hourLabels[] = sprintf('%02d:00', $h);
}

// 6. Recent Audit Trail Transaksi (Read-Only Mode)
$readOnlyStmt = $pdo->prepare(
    "SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, k.warna, a.nama_area,
            t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total, t.status, u.nama_lengkap AS petugas
     FROM tb_transaksi t
     JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
     JOIN tb_area_parkir a ON a.id_area = t.id_area
     JOIN tb_user u ON u.id_user = t.id_user
     WHERE t.waktu_masuk >= :st AND t.waktu_masuk <= :en
     ORDER BY t.id_parkir DESC
     LIMIT 10"
);
$readOnlyStmt->execute(['st' => $startDateTime, 'en' => $endDateTime]);
$recentAuditRows = $readOnlyStmt->fetchAll();

$pageTitle = 'Dashboard Owner & Eksekutif';
require __DIR__ . '/../includes/header.php';
?>

<!-- OWNER EXECUTIVE HERO BANNER -->
<section class="welcome-hero-banner" style="background: radial-gradient(circle at 90% 10%, rgba(16, 185, 129, 0.25) 0%, transparent 40%), linear-gradient(135deg, #070c18 0%, #0f172a 60%, #064e3b 100%);">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle fw-semibold px-3 py-1 mb-2">
                <i class="bi bi-graph-up-arrow me-1"></i>EXECUTIVE ANALYTICS & FINANCIAL AUDIT
            </span>
            <h1>Laporan Eksekutif, <?= e($_SESSION['nama_lengkap']) ?></h1>
            <p>Ringkasan performa finansial, tren pendapatan, jam sibuk operasional, dan audit transaksi parkir.</p>
        </div>
        <div class="hero-action-buttons no-print">
            <a href="<?= e(app_url("owner/dashboard.php?export=csv&mulai=$startDate&sampai=$endDate")) ?>" class="btn btn-outline-light d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-excel-fill text-success"></i><span>Unduh Excel (CSV)</span>
            </a>
            <button type="button" class="btn btn-emerald d-flex align-items-center gap-2" onclick="window.print()">
                <i class="bi bi-printer-fill"></i><span>Cetak Laporan Eksekutif</span>
            </button>
        </div>
    </div>
</section>

<!-- FILTER TANGGAL INTERAKTIF -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-auto">
                <span class="extra-small fw-bold text-muted text-uppercase letter-spacing-1">
                    <i class="bi bi-funnel-fill text-danger me-1"></i>Filter Rentang Tanggal:
                </span>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light text-muted">Dari</span>
                    <input type="date" class="form-control" name="mulai" value="<?= e($startDate) ?>" max="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light text-muted">Sampai</span>
                    <input type="date" class="form-control" name="sampai" value="<?= e($endDate) ?>" max="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary px-3">
                    <i class="bi bi-check-lg me-1"></i>Terapkan
                </button>
                <a href="<?= e(app_url('owner/dashboard.php')) ?>" class="btn btn-sm btn-light border ms-1">Reset</a>
            </div>
            <div class="col-auto ms-auto text-muted extra-small d-none d-lg-block">
                Periode Terpilih: <strong><?= date('d M Y', strtotime($startDate)) ?> – <?= date('d M Y', strtotime($endDate)) ?></strong>
            </div>
        </form>
    </div>
</div>

<!-- 4 EXECUTIVE SUMMARY CARDS -->
<div class="row g-3 mb-4">
    <!-- Stat 1: Total Omzet Bulan Ini -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern">
            <div class="stat-header">
                <div>
                    <span class="stat-kicker">Total Omzet Finansial</span>
                    <div class="stat-number text-emerald"><?= format_rupiah($totalOmzet) ?></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-emerald">
                    <i class="bi bi-cash-stack"></i>
                </div>
            </div>
            <div class="stat-footer">
                <?php if ($momPercent !== null): ?>
                    <span class="stat-badge <?= $momPercent >= 0 ? 'stat-badge-positive' : 'stat-badge-danger' ?>">
                        <i class="bi <?= $momPercent >= 0 ? 'bi-arrow-up-right' : 'bi-arrow-down-right' ?>"></i>
                        <?= abs($momPercent) ?>% vs Bulan Lalu
                    </span>
                <?php else: ?>
                    <span class="stat-badge stat-badge-neutral">Periode Berjalan</span>
                <?php endif; ?>
                <span class="extra-small text-muted fw-bold"><?= $totalSelesai ?> Transaksi Keluar</span>
            </div>
        </div>
    </div>

    <!-- Stat 2: Proyeksi Pendapatan Akhir Bulan -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern">
            <div class="stat-header">
                <div>
                    <span class="stat-kicker">Proyeksi Omzet Akhir Bulan</span>
                    <div class="stat-number text-primary"><?= format_rupiah($projectedRevenue) ?></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-sky">
                    <i class="bi bi-graph-up"></i>
                </div>
            </div>
            <div class="stat-footer">
                <span class="stat-badge stat-badge-neutral">
                    <i class="bi bi-speedometer2"></i> Rata-rata <?= format_rupiah($runRateDaily) ?>/hari
                </span>
                <span class="extra-small text-muted fw-semibold"><?= $dayOfMonth ?>/<?= $daysInMonth ?> Hari</span>
            </div>
        </div>
    </div>

    <!-- Stat 3: Jam Sibuk (Peak Hours) -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern">
            <div class="stat-header">
                <div>
                    <span class="stat-kicker">Jam Sibuk (Peak Hours)</span>
                    <div class="stat-number text-danger" style="font-size: 1.45rem;"><?= e($peakHourText) ?></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-red">
                    <i class="bi bi-clock-history"></i>
                </div>
            </div>
            <div class="stat-footer">
                <span class="stat-badge stat-badge-danger">
                    <i class="bi bi-fire"></i> Volume Tertinggi
                </span>
                <span class="extra-small text-muted fw-semibold">Waktu Masuk</span>
            </div>
        </div>
    </div>

    <!-- Stat 4: Average Stay Duration -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern">
            <div class="stat-header">
                <div>
                    <span class="stat-kicker">Rata-Rata Lama Parkir</span>
                    <div class="stat-number text-dark"><?= $avgStayHours ?> <span class="fs-6 text-muted fw-normal">Jam / Unit</span></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-amber">
                    <i class="bi bi-stopwatch-fill"></i>
                </div>
            </div>
            <div class="stat-footer">
                <span class="stat-badge stat-badge-neutral">
                    <i class="bi bi-hourglass-split"></i> Average Stay
                </span>
                <span class="extra-small text-muted fw-semibold">Kendaraan Selesai</span>
            </div>
        </div>
    </div>
</div>

<!-- CHARTS ROW 1: DAILY REVENUE TREND & REVENUE PER AREA -->
<div class="row g-3 mb-4">
    <!-- Line Chart: Tren Pendapatan Harian -->
    <div class="col-xl-8">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-white border-0 pb-0 d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fs-6 fw-bold text-dark m-0">Tren Pendapatan Finansial Harian</h2>
                    <p class="text-muted extra-small m-0">Aktivitas transaksi yang telah selesai dibayar</p>
                </div>
                <span class="badge bg-light text-secondary border small">
                    <i class="bi bi-calendar-range me-1"></i><?= date('d M', strtotime($startDate)) ?> – <?= date('d M Y', strtotime($endDate)) ?>
                </span>
            </div>
            <div class="card-body pt-3">
                <div style="position: relative; height: 280px; width: 100%;">
                    <canvas id="ownerDailyTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Bar Chart: Perbandingan Pendapatan per Area -->
    <div class="col-xl-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-white border-0 pb-0">
                <h2 class="fs-6 fw-bold text-dark m-0">Pendapatan per Area Parkir</h2>
                <p class="text-muted extra-small m-0">Kontribusi nominal per zona</p>
            </div>
            <div class="card-body pt-3 d-flex flex-column justify-content-between">
                <div style="position: relative; height: 220px; width: 100%;">
                    <canvas id="ownerAreaChart"></canvas>
                </div>
                <div class="border-top pt-2">
                    <?php foreach ($areaRows as $ar): ?>
                        <div class="d-flex justify-content-between align-items-center extra-small py-1">
                            <span class="text-muted fw-semibold"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?= e($ar['nama_area']) ?>:</span>
                            <strong class="text-dark"><?= format_rupiah($ar['omzet']) ?> (<?= $ar['total_transaksi'] ?> unit)</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CHARTS ROW 2: PEAK HOURS HEATMAP CHART & KONTRIBUSI JENIS KENDARAAN -->
<div class="row g-3 mb-4">
    <!-- Peak Hours Heatmap / Bar Chart 24 Jam -->
    <div class="col-xl-8">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-white border-0 pb-0 d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fs-6 fw-bold text-dark m-0">Pola Jam-Jam Tersibuk (Peak Hours Distribution)</h2>
                    <p class="text-muted extra-small m-0">Distribusi jumlah kendaraan masuk selama 24 jam</p>
                </div>
                <span class="badge bg-danger-subtle text-danger extra-small">24-Hour Heatmap</span>
            </div>
            <div class="card-body pt-3">
                <div style="position: relative; height: 260px; width: 100%;">
                    <canvas id="ownerPeakHoursChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Kontribusi Jenis Kendaraan -->
    <div class="col-xl-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-white border-0 pb-0">
                <h2 class="fs-6 fw-bold text-dark m-0">Kontribusi Jenis Kendaraan</h2>
                <p class="text-muted extra-small m-0">Distribusi omzet berdasarkan tipe armada</p>
            </div>
            <div class="card-body pt-3">
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($typeRows as $t): ?>
                        <?php 
                        $pct = $totalOmzet > 0 ? round(((float)$t['omzet'] / $totalOmzet) * 100, 1) : 0;
                        ?>
                        <div class="p-3 border rounded-3 bg-light">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold text-dark small text-uppercase">
                                    <i class="bi <?= strtolower($t['jenis_kendaraan']) === 'motor' ? 'bi-bicycle' : 'bi-car-front-fill' ?> me-1 text-danger"></i>
                                    <?= e($t['jenis_kendaraan']) ?>
                                </span>
                                <strong class="text-emerald"><?= format_rupiah($t['omzet']) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between extra-small text-muted mb-1">
                                <span><?= $t['total_unit'] ?> Kendaraan Selesai</span>
                                <span><?= $pct ?>% Pangsa Pasar</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-emerald" style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- AUDIT TRAIL RINGKAS TRANSAKSI (READ-ONLY MODE) -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
        <div>
            <span class="badge bg-dark extra-small text-uppercase mb-1"><i class="bi bi-shield-lock me-1"></i>Read-Only Audit Trail</span>
            <h2 class="fs-6 fw-bold text-dark m-0">Audit Transaksi Terkini (Pengawasan Eksekutif)</h2>
            <p class="text-muted extra-small m-0">Data transaksi terlindungi, tanpa opsi ubah/hapus demi integritas audit finansial</p>
        </div>
        <a href="<?= e(app_url('owner/rekap_transaksi.php')) ?>" class="btn btn-sm btn-outline-primary no-print">
            <i class="bi bi-file-earmark-bar-graph me-1"></i>Buka Rekapitulasi Lengkap
        </a>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Tiket ID</th>
                    <th>Plat Nomor</th>
                    <th>Jenis & Warna</th>
                    <th>Area Parkir</th>
                    <th>Waktu Masuk</th>
                    <th>Waktu Keluar</th>
                    <th>Durasi</th>
                    <th>Total Bayar</th>
                    <th>Petugas</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentAuditRows): ?>
                    <?php foreach ($recentAuditRows as $audit): ?>
                        <tr>
                            <td><span class="badge bg-light text-secondary border font-monospace">#<?= (int)$audit['id_parkir'] ?></span></td>
                            <td><strong class="font-monospace text-dark"><?= e($audit['plat_nomor']) ?></strong></td>
                            <td class="small"><?= e(ucfirst($audit['jenis_kendaraan'])) ?> &bull; <?= e($audit['warna']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($audit['nama_area']) ?></span></td>
                            <td class="extra-small text-muted"><?= e(date('d/m/Y H:i', strtotime($audit['waktu_masuk']))) ?></td>
                            <td class="extra-small text-muted"><?= $audit['waktu_keluar'] ? e(date('d/m/Y H:i', strtotime($audit['waktu_keluar']))) : '<span class="text-muted">-</span>' ?></td>
                            <td class="small fw-semibold"><?= (int)$audit['durasi_jam'] ?> Jam</td>
                            <td class="fw-bold text-dark"><?= $audit['biaya_total'] ? format_rupiah($audit['biaya_total']) : '-' ?></td>
                            <td class="small text-muted"><i class="bi bi-person me-1"></i><?= e($audit['petugas']) ?></td>
                            <td>
                                <?php if ($audit['status'] === 'masuk'): ?>
                                    <span class="badge badge-masuk">Sedang Parkir</span>
                                <?php else: ?>
                                    <span class="badge badge-keluar">Selesai</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="10" class="text-center py-4 text-muted small">Belum ada transaksi pada periode yang dipilih.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SCRIPTS: OWNER CHARTS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    // 1. Daily Trend Line Chart
    const trendCtx = document.getElementById('ownerDailyTrendChart');
    if (trendCtx) {
        const ctx = trendCtx.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 260);
        grad.addColorStop(0, 'rgba(16, 185, 129, 0.25)');
        grad.addColorStop(1, 'rgba(16, 185, 129, 0.01)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($trendDates) ?>,
                datasets: [{
                    label: 'Pendapatan (Rp)',
                    data: <?= json_encode($trendRev) ?>,
                    borderColor: '#10b981',
                    backgroundColor: grad,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#10b981',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(c) {
                                return ' Omzet: Rp ' + c.raw.toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        ticks: { callback: v => 'Rp ' + (v >= 1000 ? (v/1000) + 'k' : v) },
                        grid: { color: 'rgba(226, 232, 240, 0.6)' }
                    }
                }
            }
        });
    }

    // 2. Area Bar Chart
    const areaCtx = document.getElementById('ownerAreaChart');
    if (areaCtx) {
        new Chart(areaCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($areaLabels) ?>,
                datasets: [{
                    label: 'Omzet per Area',
                    data: <?= json_encode($areaValues) ?>,
                    backgroundColor: ['#e50914', '#0ea5e9', '#10b981', '#8b5cf6'],
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: c => ' Rp ' + c.raw.toLocaleString('id-ID')
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        ticks: { callback: v => 'Rp ' + (v >= 1000 ? (v/1000) + 'k' : v) },
                        grid: { color: 'rgba(226, 232, 240, 0.6)' }
                    }
                }
            }
        });
    }

    // 3. Peak Hours 24-Hour Bar Heatmap
    const peakCtx = document.getElementById('ownerPeakHoursChart');
    if (peakCtx) {
        const peakData = <?= json_encode(array_values($hourDistribution)) ?>;
        const maxVal = Math.max(...peakData, 1);
        
        // Dynamic heatmap background color: brighter red for peak hours
        const bgColors = peakData.map(v => {
            const intensity = v / maxVal;
            if (intensity > 0.75) return '#e50914'; // high peak
            if (intensity > 0.4) return '#f59e0b'; // medium
            return '#cbd5e1'; // low
        });

        new Chart(peakCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($hourLabels) ?>,
                datasets: [{
                    label: 'Volume Kendaraan Masuk',
                    data: peakData,
                    backgroundColor: bgColors,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: c => ' Masuk: ' + c.raw + ' Kendaraan'
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        ticks: { stepSize: 1 },
                        grid: { color: 'rgba(226, 232, 240, 0.6)' }
                    }
                }
            }
        });
    }
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
