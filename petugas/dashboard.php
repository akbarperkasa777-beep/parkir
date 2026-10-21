<?php
require_once __DIR__ . '/../includes/app.php';
require_role('petugas');

$pdo = db();
$today = new DateTimeImmutable('today');
$tomorrow = $today->modify('+1 day');
$monthStart = $today->modify('first day of this month');
$nextMonth = $monthStart->modify('+1 month');
$chartStart = $today->modify('-6 days');

$loadDashboardData = static function () use ($pdo, $today, $tomorrow, $monthStart, $nextMonth, $chartStart): array {
    $statsStatement = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN status = 'keluar' AND waktu_keluar >= :today_start AND waktu_keluar < :tomorrow_start THEN COALESCE(biaya_total, 0) ELSE 0 END) AS revenue_today,
            SUM(CASE WHEN status = 'keluar' AND waktu_keluar >= :month_start AND waktu_keluar < :next_month THEN COALESCE(biaya_total, 0) ELSE 0 END) AS revenue_month,
            SUM(CASE WHEN waktu_masuk >= :today_start_count AND waktu_masuk < :tomorrow_start_count THEN 1 ELSE 0 END) AS entries_today
         FROM tb_transaksi"
    );
    $statsStatement->execute([
        'today_start' => $today->format('Y-m-d 00:00:00'),
        'tomorrow_start' => $tomorrow->format('Y-m-d 00:00:00'),
        'month_start' => $monthStart->format('Y-m-d 00:00:00'),
        'next_month' => $nextMonth->format('Y-m-d 00:00:00'),
        'today_start_count' => $today->format('Y-m-d 00:00:00'),
        'tomorrow_start_count' => $tomorrow->format('Y-m-d 00:00:00'),
    ]);
    $stats = $statsStatement->fetch() ?: [];

    $areas = $pdo->query(
        "SELECT a.id_area, a.nama_area, a.kapasitas, COUNT(t.id_parkir) AS terisi
         FROM tb_area_parkir a
         LEFT JOIN tb_transaksi t ON t.id_area = a.id_area AND t.status = 'masuk'
         GROUP BY a.id_area, a.nama_area, a.kapasitas
         ORDER BY a.nama_area"
    )->fetchAll();

    $activeVehicles = $pdo->query(
        "SELECT t.id_parkir, t.id_area, t.waktu_masuk, k.plat_nomor, k.jenis_kendaraan
         FROM tb_transaksi t
         JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
         WHERE t.status = 'masuk'
         ORDER BY t.waktu_masuk ASC, t.id_parkir ASC"
    )->fetchAll();
    $vehiclesByArea = [];
    foreach ($activeVehicles as $vehicle) {
        $vehiclesByArea[(int) $vehicle['id_area']][] = [
            'id_parkir' => (int) $vehicle['id_parkir'],
            'plat_nomor' => $vehicle['plat_nomor'],
            'jenis_kendaraan' => $vehicle['jenis_kendaraan'],
            'waktu_masuk' => date('H:i', strtotime($vehicle['waktu_masuk'])),
        ];
    }

    $capacity = 0;
    $occupied = 0;
    foreach ($areas as &$area) {
        $area['id_area'] = (int) $area['id_area'];
        $area['kapasitas'] = max(0, (int) $area['kapasitas']);
        $area['kendaraan_aktif'] = $vehiclesByArea[$area['id_area']] ?? [];
        $area['terisi'] = count($area['kendaraan_aktif']);
        $capacity += $area['kapasitas'];
        $occupied += $area['terisi'];
    }
    unset($area);

    $bookingSupported = false;
    $activeBookings = null;
    $bookingTable = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'tb_booking'"
    );
    $bookingTable->execute();
    if ((int) $bookingTable->fetchColumn() > 0) {
        $statusColumn = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'tb_booking' AND column_name = 'status'"
        );
        $statusColumn->execute();
        if ((int) $statusColumn->fetchColumn() > 0) {
            $activeBookings = (int) $pdo->query("SELECT COUNT(*) FROM tb_booking WHERE status = 'confirmed'")->fetchColumn();
            $bookingSupported = true;
        }
    }

    $dailyRevenue = [];
    $chartLabels = [];
    for ($day = $chartStart; $day <= $today; $day = $day->modify('+1 day')) {
        $key = $day->format('Y-m-d');
        $dailyRevenue[$key] = 0.0;
        $chartLabels[] = $day->format('d M');
    }
    $dailyRevenueStatement = $pdo->prepare(
        "SELECT DATE(waktu_keluar) AS tanggal, SUM(COALESCE(biaya_total, 0)) AS pendapatan
         FROM tb_transaksi
         WHERE status = 'keluar' AND waktu_keluar >= :start AND waktu_keluar < :end
         GROUP BY DATE(waktu_keluar)
         ORDER BY tanggal"
    );
    $dailyRevenueStatement->execute([
        'start' => $chartStart->format('Y-m-d 00:00:00'),
        'end' => $tomorrow->format('Y-m-d 00:00:00'),
    ]);
    foreach ($dailyRevenueStatement->fetchAll() as $row) {
        if (array_key_exists($row['tanggal'], $dailyRevenue)) {
            $dailyRevenue[$row['tanggal']] = (float) $row['pendapatan'];
        }
    }

    $typeCounts = ['Motor' => 0, 'Mobil' => 0, 'Kendaraan Besar' => 0];
    $typeStatement = $pdo->query(
        "SELECT LOWER(k.jenis_kendaraan) AS jenis, COUNT(*) AS total
         FROM tb_transaksi t
         JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
         WHERE t.status = 'masuk'
         GROUP BY LOWER(k.jenis_kendaraan)"
    );
    foreach ($typeStatement->fetchAll() as $row) {
        $type = strtolower((string) $row['jenis']);
        $label = in_array($type, ['motor', 'sepeda motor', 'bike'], true)
            ? 'Motor'
            : (in_array($type, ['mobil', 'car'], true) ? 'Mobil' : 'Kendaraan Besar');
        $typeCounts[$label] += (int) $row['total'];
    }

    $recentTransactions = $pdo->query(
        "SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, a.nama_area, t.waktu_masuk,
                t.waktu_keluar, t.status, t.biaya_total
         FROM tb_transaksi t
         JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
         JOIN tb_area_parkir a ON a.id_area = t.id_area
         ORDER BY t.id_parkir DESC
         LIMIT 8"
    )->fetchAll();

    return [
        'stats' => [
            'revenue_today' => (float) ($stats['revenue_today'] ?? 0),
            'revenue_month' => (float) ($stats['revenue_month'] ?? 0),
            'entries_today' => (int) ($stats['entries_today'] ?? 0),
            'capacity' => $capacity,
            'occupied' => $occupied,
            'available' => max(0, $capacity - $occupied),
            'occupancy_percent' => $capacity > 0 ? min(100, round(($occupied / $capacity) * 100, 1)) : 0,
            'active_bookings' => $activeBookings,
            'booking_supported' => $bookingSupported,
        ],
        'areas' => $areas,
        'chart' => [
            'labels' => $chartLabels,
            'revenue' => array_values($dailyRevenue),
            'vehicle_labels' => array_keys($typeCounts),
            'vehicle_counts' => array_values($typeCounts),
        ],
        'recent' => $recentTransactions,
        'updated_at' => date('H:i:s'),
    ];
};

if (isset($_GET['dashboard_live']) && $_GET['dashboard_live'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    try {
        echo json_encode($loadDashboardData(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('Gagal memperbarui dashboard petugas: ' . $exception->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'Data monitoring belum dapat diperbarui.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

try {
    $dashboard = $loadDashboardData();
} catch (Throwable $exception) {
    error_log('Gagal memuat dashboard petugas: ' . $exception->getMessage());
    http_response_code(503);
    exit('Dashboard tidak dapat dimuat saat ini. Silakan coba kembali.');
}

$pageTitle = 'Dashboard Monitoring Petugas';
require __DIR__ . '/../includes/header.php';
?>

<section class="welcome-hero-banner mb-4" style="background: radial-gradient(circle at 85% 8%, rgba(229,9,20,.22) 0%, transparent 36%), linear-gradient(135deg,#0b1220 0%,#172337 68%,#203348 100%);">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <span class="badge rounded-pill bg-white bg-opacity-10 text-white border border-white border-opacity-25 px-3 py-1 mb-2">
                <i class="bi bi-activity me-1"></i>MONITORING OPERASIONAL
            </span>
            <h1 class="h3 text-white fw-bold mb-1">Ringkasan Operasional</h1>
            <p class="text-white-50 mb-0">Pantau performa transaksi, ketersediaan area, dan aktivitas parkir secara langsung.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-danger" href="<?= e(app_url('petugas/transaksi_masuk.php')) ?>"><i class="bi bi-box-arrow-in-down-left me-1"></i>Parkir Masuk</a>
            <a class="btn btn-outline-light" href="<?= e(app_url('petugas/transaksi_keluar.php')) ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Parkir Keluar</a>
        </div>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <article class="card border-0 shadow-sm h-100 dashboard-glass-card">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <span class="stat-kicker">Pendapatan Hari Ini</span>
                        <div class="stat-number text-dark fs-4" id="dashboardRevenueToday"><?= e(format_rupiah($dashboard['stats']['revenue_today'])) ?></div>
                        <small class="text-muted">Bulan ini: <strong id="dashboardRevenueMonth"><?= e(format_rupiah($dashboard['stats']['revenue_month'])) ?></strong></small>
                    </div>
                    <span class="stat-icon-wrapper stat-icon-emerald"><i class="bi bi-wallet2"></i></span>
                </div>
            </div>
        </article>
    </div>
    <div class="col-sm-6 col-xl-3">
        <article class="card border-0 shadow-sm h-100 dashboard-glass-card">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <span class="stat-kicker">Kendaraan Masuk Hari Ini</span>
                        <div class="stat-number text-primary fs-3" id="dashboardEntriesToday"><?= number_format($dashboard['stats']['entries_today'], 0, ',', '.') ?></div>
                        <small class="text-muted">Unit kendaraan</small>
                    </div>
                    <span class="stat-icon-wrapper stat-icon-sky"><i class="bi bi-box-arrow-in-down-left"></i></span>
                </div>
            </div>
        </article>
    </div>
    <div class="col-sm-6 col-xl-3">
        <article class="card border-0 shadow-sm h-100 dashboard-glass-card">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="w-100">
                        <span class="stat-kicker">Keterisian Slot Real-Time</span>
                        <div class="stat-number text-dark fs-4"><span id="dashboardOccupied"><?= $dashboard['stats']['occupied'] ?></span> / <span id="dashboardCapacity"><?= $dashboard['stats']['capacity'] ?></span></div>
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span><span id="dashboardAvailable"><?= $dashboard['stats']['available'] ?></span> tersedia</span>
                            <strong id="dashboardOccupancyPercent"><?= e((string) $dashboard['stats']['occupancy_percent']) ?>%</strong>
                        </div>
                        <div class="progress" style="height:6px"><div class="progress-bar bg-danger" id="dashboardOccupancyBar" style="width:<?= e((string) $dashboard['stats']['occupancy_percent']) ?>%"></div></div>
                    </div>
                    <span class="stat-icon-wrapper stat-icon-red"><i class="bi bi-p-square"></i></span>
                </div>
            </div>
        </article>
    </div>
    <div class="col-sm-6 col-xl-3">
        <article class="card border-0 shadow-sm h-100 dashboard-glass-card">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <span class="stat-kicker">Booking Online Aktif</span>
                        <div class="stat-number text-dark fs-3" id="dashboardBookings"><?= $dashboard['stats']['booking_supported'] ? number_format((int) $dashboard['stats']['active_bookings'], 0, ',', '.') : '—' ?></div>
                        <small class="text-muted" id="dashboardBookingNote"><?= $dashboard['stats']['booking_supported'] ? 'Booking terkonfirmasi' : 'Modul booking belum tersedia' ?></small>
                    </div>
                    <span class="stat-icon-wrapper stat-icon-amber"><i class="bi bi-calendar2-check"></i></span>
                </div>
            </div>
        </article>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-3 px-3">
                <h2 class="fs-6 fw-bold text-dark mb-1">Tren Pendapatan Harian</h2>
                <p class="text-muted extra-small mb-0">Pendapatan transaksi selesai dalam 7 hari terakhir</p>
            </div>
            <div class="card-body pt-2"><div style="height:280px"><canvas id="staffRevenueChart" aria-label="Grafik tren pendapatan harian" role="img"></canvas></div></div>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-3 px-3">
                <h2 class="fs-6 fw-bold text-dark mb-1">Kendaraan Sedang Parkir</h2>
                <p class="text-muted extra-small mb-0">Komposisi kendaraan aktif per jenis</p>
            </div>
            <div class="card-body pt-2"><div style="height:280px"><canvas id="staffVehicleChart" aria-label="Grafik jenis kendaraan aktif" role="img"></canvas></div></div>
        </section>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12">
        <section class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h2 class="fs-6 fw-bold text-dark mb-1">Monitoring Denah Area Parkir</h2>
                    <p class="text-muted extra-small mb-0">Pembaruan otomatis; denah ini hanya untuk pemantauan.</p>
                </div>
                <span class="badge rounded-pill bg-success-subtle text-success border px-3 py-2"><span class="pulse-indicator pulse-emerald me-1"></span>Live <span id="dashboardUpdatedAt"><?= e($dashboard['updated_at']) ?></span></span>
            </div>
            <div class="card-body pt-0"><div id="staffDashboardParkingGrid"></div></div>
        </section>
    </div>
</div>

<section class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between gap-2">
        <div>
            <h2 class="fs-6 fw-bold text-dark mb-1">Aktivitas Transaksi Terbaru</h2>
            <p class="text-muted extra-small mb-0">Riwayat read-only untuk pemantauan operasional</p>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(app_url('petugas/riwayat_transaksi.php')) ?>">Lihat Riwayat</a>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>No. Tiket</th><th>Plat Nomor</th><th>Jenis</th><th>Area</th><th>Waktu</th><th>Status</th><th class="text-end">Biaya</th></tr></thead>
            <tbody id="dashboardRecentRows">
                <?php if ($dashboard['recent']): ?>
                    <?php foreach ($dashboard['recent'] as $transaction): ?>
                        <tr>
                            <td><span class="badge bg-light text-secondary border">#<?= (int) $transaction['id_parkir'] ?></span></td>
                            <td class="font-monospace fw-bold"><?= e($transaction['plat_nomor']) ?></td>
                            <td><?= e(ucfirst($transaction['jenis_kendaraan'])) ?></td>
                            <td><?= e($transaction['nama_area']) ?></td>
                            <td class="text-muted small"><?= e(date('d M Y, H:i', strtotime($transaction['waktu_masuk']))) ?></td>
                            <td><span class="badge <?= $transaction['status'] === 'masuk' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>"><?= $transaction['status'] === 'masuk' ? 'Sedang Parkir' : 'Selesai' ?></span></td>
                            <td class="text-end fw-semibold"><?= $transaction['biaya_total'] !== null ? e(format_rupiah($transaction['biaya_total'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted small">Belum ada aktivitas transaksi.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<style>
.dashboard-glass-card{position:relative;overflow:hidden;border:1px solid rgba(255,255,255,.78)!important;border-radius:18px;background:linear-gradient(145deg,rgba(255,255,255,.98),rgba(247,249,252,.92));box-shadow:0 10px 28px rgba(15,23,42,.055),inset 0 1px 0 rgba(255,255,255,.9)!important}
.dashboard-glass-card:after{position:absolute;right:-30px;bottom:-48px;width:110px;height:110px;border-radius:50%;background:rgba(16,185,129,.055);content:"";pointer-events:none}
</style>
<script src="<?= e(app_url('assets/parking-grid.js')) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const initial = <?= json_encode($dashboard, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK) ?>;
    const money = value => 'Rp ' + Number(value || 0).toLocaleString('id-ID');
    const setText = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    };
    const revenueCanvas = document.getElementById('staffRevenueChart');
    const revenueContext = revenueCanvas.getContext('2d');
    const gradient = revenueContext.createLinearGradient(0, 0, 0, 280);
    gradient.addColorStop(0, 'rgba(229,9,20,.24)');
    gradient.addColorStop(1, 'rgba(229,9,20,0)');
    const revenueChart = new Chart(revenueCanvas, {
        type: 'line',
        data: {
            labels: initial.chart.labels,
            datasets: [{
                label: 'Pendapatan (Rp)',
                data: initial.chart.revenue,
                borderColor: '#e50914',
                backgroundColor: gradient,
                fill: true,
                tension: .38,
                borderWidth: 3,
                pointRadius: 3,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: item => money(item.raw) } } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,.16)' }, ticks: { callback: value => 'Rp ' + Number(value).toLocaleString('id-ID') } },
                x: { grid: { display: false } }
            }
        }
    });
    const vehicleChart = new Chart(document.getElementById('staffVehicleChart'), {
        type: 'doughnut',
        data: {
            labels: initial.chart.vehicle_labels,
            datasets: [{ data: initial.chart.vehicle_counts, backgroundColor: ['#e50914', '#10b981', '#f59e0b'], borderColor: '#fff', borderWidth: 4, hoverOffset: 7 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 18 } } }
        }
    });

    const visualizer = new ParkingGridVisualizer('staffDashboardParkingGrid', {
        readOnly: true
    });
    visualizer.setData(initial.areas);

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[character]);
    }
    function renderRecent(rows) {
        const body = document.getElementById('dashboardRecentRows');
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted small">Belum ada aktivitas transaksi.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(row => {
            const active = row.status === 'masuk';
            const time = new Date(String(row.waktu_masuk).replace(' ', 'T'));
            const formattedTime = Number.isNaN(time.getTime()) ? row.waktu_masuk : time.toLocaleString('id-ID', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:false});
            return `<tr>
                <td><span class="badge bg-light text-secondary border">#${Number(row.id_parkir)}</span></td>
                <td class="font-monospace fw-bold">${escapeHtml(row.plat_nomor)}</td>
                <td>${escapeHtml(String(row.jenis_kendaraan || '').replace(/^./, c => c.toUpperCase()))}</td>
                <td>${escapeHtml(row.nama_area)}</td>
                <td class="text-muted small">${escapeHtml(formattedTime)}</td>
                <td><span class="badge ${active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'}">${active ? 'Sedang Parkir' : 'Selesai'}</span></td>
                <td class="text-end fw-semibold">${row.biaya_total === null ? '—' : money(row.biaya_total)}</td>
            </tr>`;
        }).join('');
    }
    function updateDashboard(data) {
        const stats = data.stats;
        setText('dashboardRevenueToday', money(stats.revenue_today));
        setText('dashboardRevenueMonth', money(stats.revenue_month));
        setText('dashboardEntriesToday', Number(stats.entries_today).toLocaleString('id-ID'));
        setText('dashboardOccupied', stats.occupied);
        setText('dashboardCapacity', stats.capacity);
        setText('dashboardAvailable', stats.available);
        setText('dashboardOccupancyPercent', stats.occupancy_percent + '%');
        document.getElementById('dashboardOccupancyBar').style.width = stats.occupancy_percent + '%';
        setText('dashboardBookings', stats.booking_supported ? Number(stats.active_bookings).toLocaleString('id-ID') : '—');
        setText('dashboardBookingNote', stats.booking_supported ? 'Booking terkonfirmasi' : 'Modul booking belum tersedia');
        setText('dashboardUpdatedAt', data.updated_at);
        revenueChart.data.labels = data.chart.labels;
        revenueChart.data.datasets[0].data = data.chart.revenue;
        revenueChart.update('none');
        vehicleChart.data.labels = data.chart.vehicle_labels;
        vehicleChart.data.datasets[0].data = data.chart.vehicle_counts;
        vehicleChart.update('none');
        visualizer.setData(data.areas);
        renderRecent(data.recent);
    }
    async function refreshDashboard() {
        try {
            const response = await fetch('dashboard.php?dashboard_live=1', {headers: {'X-Requested-With':'XMLHttpRequest'}, cache:'no-store'});
            if (!response.ok) throw new Error('HTTP ' + response.status);
            updateDashboard(await response.json());
        } catch (error) {
            console.error('Dashboard monitoring update failed:', error);
            setText('dashboardUpdatedAt', 'Gagal memperbarui');
        }
    }
    refreshDashboard();
    window.setInterval(() => {
        if (!document.hidden) refreshDashboard();
    }, 15000);
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
