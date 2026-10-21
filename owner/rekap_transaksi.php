<?php
require_once __DIR__ . '/../includes/app.php';
require_role('owner');

$pdo = db();
$basis = (string) ($_GET['basis'] ?? 'masuk');
if (!in_array($basis, ['masuk', 'keluar'], true)) {
    $basis = 'masuk';
}
$startDate = trim((string) ($_GET['mulai'] ?? date('Y-m-01')));
$endDate = trim((string) ($_GET['sampai'] ?? date('Y-m-d')));
$vehicleType = trim((string) ($_GET['jenis'] ?? ''));
$areaName = trim((string) ($_GET['area'] ?? ''));
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
$isValidDate = static function (string $value) use ($datePattern): bool {
    if (!preg_match($datePattern, $value)) {
        return false;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
$errors = [];

if (!$isValidDate($startDate)) {
    $errors[] = 'Tanggal mulai tidak valid.';
}
if (!$isValidDate($endDate)) {
    $errors[] = 'Tanggal akhir tidak valid.';
}
if (!$errors && $startDate > $endDate) {
    $errors[] = 'Tanggal mulai tidak boleh melewati tanggal akhir.';
}

$vehicleTypes = $pdo->query('SELECT jenis_kendaraan FROM tb_tarif ORDER BY jenis_kendaraan')->fetchAll(PDO::FETCH_COLUMN);
$areaNames = $pdo->query('SELECT nama_area FROM tb_area_parkir ORDER BY nama_area')->fetchAll(PDO::FETCH_COLUMN);
if ($vehicleType !== '' && !in_array($vehicleType, $vehicleTypes, true)) {
    $errors[] = 'Filter jenis kendaraan tidak valid.';
}
if ($areaName !== '' && !in_array($areaName, $areaNames, true)) {
    $errors[] = 'Filter area parkir tidak valid.';
}

$dateColumn = $basis === 'masuk' ? 'waktu_masuk' : 'waktu_keluar';
$where = [
    $dateColumn . ' >= :start_date',
    $dateColumn . ' < DATE_ADD(:end_date, INTERVAL 1 DAY)',
];
$params = [
    'start_date' => $startDate . ' 00:00:00',
    'end_date' => $endDate . ' 00:00:00',
];
if ($vehicleType !== '') {
    $where[] = 'jenis_kendaraan = :jenis_kendaraan';
    $params['jenis_kendaraan'] = $vehicleType;
}
if ($areaName !== '') {
    $where[] = 'nama_area = :nama_area';
    $params['nama_area'] = $areaName;
}
$whereSql = implode(' AND ', $where);

$rows = [];
$totalRecords = 0;
$grandRevenue = 0.0;
$completedCount = 0;
$currentPage = 1;
$totalPages = 1;
$perPage = 25;
$displayRows = [];

if (!$errors) {
    $summaryStatement = $pdo->prepare(
        "SELECT COUNT(*) AS total_records,
                SUM(CASE WHEN status = 'keluar' THEN 1 ELSE 0 END) AS completed_count,
                COALESCE(SUM(CASE WHEN status = 'keluar' THEN biaya_total ELSE 0 END), 0) AS grand_revenue
         FROM v_rekap_transaksi
         WHERE {$whereSql}"
    );
    $summaryStatement->execute($params);
    $summary = $summaryStatement->fetch();
    $totalRecords = (int) $summary['total_records'];
    $completedCount = (int) $summary['completed_count'];
    $grandRevenue = (float) $summary['grand_revenue'];

    $pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $currentPage = max(1, $pageValue ?: 1);
    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    $currentPage = min($currentPage, $totalPages);

    $selectColumns = 'id_parkir, waktu_masuk, waktu_keluar, durasi_jam, biaya_total, status, plat_nomor, jenis_kendaraan, pemilik, nama_area, tarif_per_jam';
    $baseQuery = "SELECT {$selectColumns} FROM v_rekap_transaksi WHERE {$whereSql} ORDER BY {$dateColumn} DESC, id_parkir DESC";

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $exportStatement = $pdo->prepare($baseQuery);
        $exportStatement->execute($params);
        $rows = $exportStatement->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rekap-parkir-' . $startDate . '-' . $endDate . '.csv"');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            throw new RuntimeException('File export tidak dapat dibuat.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['No. Parkir', 'Plat Nomor', 'Jenis', 'Pemilik', 'Area', 'Waktu Masuk', 'Waktu Keluar', 'Durasi (jam)', 'Biaya', 'Status']);
        $safeCsv = static function ($value): string {
            $value = (string) $value;
            return preg_match('/^[\s]*[=+\-@]/', $value) ? "'" . $value : $value;
        };
        foreach ($rows as $row) {
            fputcsv($output, [
                (int) $row['id_parkir'],
                $safeCsv($row['plat_nomor']),
                $safeCsv($row['jenis_kendaraan']),
                $safeCsv($row['pemilik']),
                $safeCsv($row['nama_area']),
                $row['waktu_masuk'],
                $row['waktu_keluar'],
                $row['durasi_jam'],
                $row['biaya_total'],
                $row['status'],
            ]);
        }
        fputcsv($output, ['', '', '', '', '', '', 'GRAND TOTAL PENDAPATAN', '', $grandRevenue, '']);
        fclose($output);
        exit;
    }

    $dataStatement = $pdo->prepare($baseQuery . ' LIMIT :limit OFFSET :offset');
    foreach ($params as $name => $value) {
        $dataStatement->bindValue(':' . $name, $value, PDO::PARAM_STR);
    }
    $dataStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStatement->bindValue(':offset', ($currentPage - 1) * $perPage, PDO::PARAM_INT);
    $dataStatement->execute();
    $displayRows = $dataStatement->fetchAll();
}

$pageTitle = 'Rekap Transaksi';
require __DIR__ . '/../includes/header.php';
?>
<div class="owner-report-hero">
    <div>
        <span class="dashboard-kicker">FINANCIAL REPORTING</span>
        <h1>Rekapitulasi Transaksi</h1>
        <p>Analisis transaksi dan pendapatan berdasarkan periode, jenis kendaraan, dan area.</p>
    </div>
    <div class="owner-report-hero-icon" aria-hidden="true"><i class="bi bi-file-earmark-bar-graph"></i></div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<section class="card mb-4 no-print">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-3"><span class="stat-icon purple"><i class="bi bi-funnel"></i></span><div><h2 class="section-title">Filter laporan</h2><p class="small text-muted mb-0 mt-1">Pilih rentang dan kategori data yang ingin ditinjau.</p></div></div>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-sm-6 col-lg-2">
                <label class="form-label" for="basis">Tanggal berdasarkan</label>
                <select class="form-select" id="basis" name="basis">
                    <option value="masuk" <?= $basis === 'masuk' ? 'selected' : '' ?>>Waktu masuk</option>
                    <option value="keluar" <?= $basis === 'keluar' ? 'selected' : '' ?>>Waktu keluar</option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2"><label class="form-label" for="mulai">Dari tanggal</label><input class="form-control" type="date" id="mulai" name="mulai" value="<?= e($startDate) ?>" required></div>
            <div class="col-sm-6 col-lg-2"><label class="form-label" for="sampai">Sampai tanggal</label><input class="form-control" type="date" id="sampai" name="sampai" value="<?= e($endDate) ?>" required></div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label" for="jenis">Jenis kendaraan</label>
                <select class="form-select" id="jenis" name="jenis">
                    <option value="">Semua jenis</option>
                    <?php foreach ($vehicleTypes as $type): ?><option value="<?= e($type) ?>" <?= $vehicleType === $type ? 'selected' : '' ?>><?= e(ucfirst($type)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label" for="area">Area parkir</label>
                <select class="form-select" id="area" name="area">
                    <option value="">Semua area</option>
                    <?php foreach ($areaNames as $name): ?><option value="<?= e($name) ?>" <?= $areaName === $name ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1 text-nowrap" type="submit"><i class="bi bi-search me-1"></i>Tampilkan</button>
                <a class="btn btn-outline-secondary text-nowrap" href="<?= e(app_url('owner/rekap_transaksi.php')) ?>" aria-label="Reset filter">Reset</a>
            </div>
        </form>
    </div>
</section>

<?php if (!$errors): ?>
    <div class="owner-report-summary row g-3 mb-4">
        <div class="col-sm-4"><article class="card owner-report-stat h-100"><div class="card-body"><span class="stat-icon red"><i class="bi bi-receipt"></i></span><div><span class="stat-label">Total transaksi</span><strong><?= number_format($totalRecords, 0, ',', '.') ?></strong></div></div></article></div>
        <div class="col-sm-4"><article class="card owner-report-stat h-100"><div class="card-body"><span class="stat-icon blue"><i class="bi bi-check2-circle"></i></span><div><span class="stat-label">Transaksi selesai</span><strong><?= number_format($completedCount, 0, ',', '.') ?></strong></div></div></article></div>
        <div class="col-sm-4"><article class="card owner-report-stat h-100"><div class="card-body"><span class="stat-icon green"><i class="bi bi-wallet2"></i></span><div><span class="stat-label">Grand total pendapatan</span><strong><?= e(format_rupiah($grandRevenue)) ?></strong></div></div></article></div>
    </div>

    <section class="card owner-report-table-card">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div><h2 class="section-title">Detail transaksi</h2><div class="small text-muted mt-1"><?= e($startDate) ?> – <?= e($endDate) ?> · <?= $basis === 'masuk' ? 'berdasarkan waktu masuk' : 'berdasarkan waktu keluar' ?></div></div>
                <div class="d-flex flex-wrap gap-2 no-print">
                    <button class="btn btn-outline-secondary" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Cetak / PDF</button>
                    <a class="btn btn-outline-primary" href="<?= e(app_url('owner/rekap_transaksi.php?' . http_build_query(['basis' => $basis, 'mulai' => $startDate, 'sampai' => $endDate, 'jenis' => $vehicleType, 'area' => $areaName, 'export' => 'csv']))) ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Ekspor CSV</a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle owner-report-table">
                    <thead><tr><th>ID Parkir</th><th>Plat</th><th>Jenis</th><th>Pemilik</th><th>Area</th><th>Waktu Masuk</th><th>Waktu Keluar</th><th>Durasi</th><th>Tarif/Jam</th><th>Total Biaya</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($displayRows as $row): ?>
                        <tr>
                            <td class="fw-semibold">#<?= (int) $row['id_parkir'] ?></td>
                            <td><?= e($row['plat_nomor']) ?></td>
                            <td><?= e(ucfirst($row['jenis_kendaraan'])) ?></td>
                            <td><?= e($row['pemilik']) ?></td>
                            <td><?= e($row['nama_area']) ?></td>
                            <td class="text-nowrap"><?= e($row['waktu_masuk']) ?></td>
                            <td class="text-nowrap"><?= e($row['waktu_keluar'] ?: '-') ?></td>
                            <td class="text-nowrap"><?= $row['durasi_jam'] === null ? '-' : (int) $row['durasi_jam'] . ' jam' ?></td>
                            <td class="text-nowrap"><?= e(format_rupiah($row['tarif_per_jam'])) ?></td>
                            <td class="text-nowrap fw-semibold"><?= $row['biaya_total'] === null ? '-' : e(format_rupiah($row['biaya_total'])) ?></td>
                            <td><?php if ($row['status'] === 'keluar'): ?><span class="badge text-bg-success">Keluar</span><?php else: ?><span class="badge text-bg-warning">Masuk</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$totalRecords): ?><tr><td colspan="11" class="table-empty">Tidak ada transaksi yang sesuai dengan filter laporan.</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="owner-report-total"><th colspan="9" class="text-end">Grand total pendapatan (transaksi selesai)</th><th class="text-nowrap"><?= e(format_rupiah($grandRevenue)) ?></th><th></th></tr>
                    </tfoot>
                </table>
            </div>
            <?php if ($totalPages > 1):
                $pageUrl = static function (int $page) use ($basis, $startDate, $endDate, $vehicleType, $areaName): string {
                    return app_url('owner/rekap_transaksi.php?' . http_build_query([
                        'basis' => $basis,
                        'mulai' => $startDate,
                        'sampai' => $endDate,
                        'jenis' => $vehicleType,
                        'area' => $areaName,
                        'page' => $page,
                    ]));
                };
                $startPage = max(1, $currentPage - 2);
                $endPage = min($totalPages, $currentPage + 2);
                ?>
                <nav class="mt-3 no-print" aria-label="Pagination transaksi">
                    <ul class="pagination pagination-sm justify-content-end mb-0">
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(max(1, $currentPage - 1))) ?>" aria-label="Sebelumnya"><i class="bi bi-chevron-left"></i></a></li>
                        <?php for ($page = $startPage; $page <= $endPage; $page++): ?><li class="page-item <?= $page === $currentPage ? 'active' : '' ?>"><a class="page-link" href="<?= e($pageUrl($page)) ?>"><?= $page ?></a></li><?php endfor; ?>
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(min($totalPages, $currentPage + 1))) ?>" aria-label="Berikutnya"><i class="bi bi-chevron-right"></i></a></li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
