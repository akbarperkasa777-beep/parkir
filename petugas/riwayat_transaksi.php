<?php
require_once __DIR__ . '/../includes/app.php';
require_role('petugas');

$pdo = db();
$startDate = trim((string) ($_GET['tanggal_mulai'] ?? ''));
$endDate = trim((string) ($_GET['tanggal_selesai'] ?? ''));
$error = '';
$transactions = [];
$hasDateFilter = $startDate !== '' || $endDate !== '';

$isValidDate = static function (string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $date));
    return checkdate($month, $day, $year);
};

if ($hasDateFilter) {
    if ($startDate === '' || $endDate === '') {
        $error = 'Isi kedua tanggal untuk memfilter riwayat.';
    } elseif (!$isValidDate($startDate) || !$isValidDate($endDate)) {
        $error = 'Format tanggal tidak valid.';
    } elseif ($startDate > $endDate) {
        $error = 'Tanggal mulai tidak boleh melewati tanggal selesai.';
    }
}

$sql = "SELECT t.id_parkir, t.waktu_masuk, t.waktu_keluar, t.durasi_jam,
               t.biaya_total, t.status, k.plat_nomor, k.jenis_kendaraan,
               a.nama_area, u.nama_lengkap AS nama_petugas
        FROM tb_transaksi t
        JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
        JOIN tb_area_parkir a ON a.id_area = t.id_area
        JOIN tb_user u ON u.id_user = t.id_user";

if ($hasDateFilter && $error === '') {
    $sql .= ' WHERE DATE(t.waktu_masuk) BETWEEN :tanggal_mulai AND :tanggal_selesai';
}
$sql .= ' ORDER BY t.waktu_masuk DESC, t.id_parkir DESC';

if ($error === '') {
    $statement = $pdo->prepare($sql);
    if ($hasDateFilter) {
        $statement->bindValue(':tanggal_mulai', $startDate, PDO::PARAM_STR);
        $statement->bindValue(':tanggal_selesai', $endDate, PDO::PARAM_STR);
    }
    $statement->execute();
    $transactions = $statement->fetchAll();
}

$pageTitle = 'Riwayat Transaksi';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Riwayat Transaksi</h1>
        <p>Telusuri transaksi parkir masuk dan keluar berdasarkan tanggal masuk.</p>
    </div>
    <div class="heading-actions no-print">
        <button class="btn btn-outline-primary" type="button" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Cetak
        </button>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end mb-4 no-print">
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="tanggal_mulai">Dari Tanggal</label>
                <input class="form-control" type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= e($startDate) ?>">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label" for="tanggal_selesai">Sampai Tanggal</label>
                <input class="form-control" type="date" id="tanggal_selesai" name="tanggal_selesai" value="<?= e($endDate) ?>">
            </div>
            <div class="col-sm-12 col-lg-auto d-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Filter / Cari</button>
                <?php if ($hasDateFilter): ?>
                    <a class="btn btn-outline-secondary" href="<?= e(app_url('petugas/riwayat_transaksi.php')) ?>">Reset</a>
                <?php endif; ?>
            </div>
            <div class="col-12">
                <p class="form-text mb-0">Filter memakai tanggal transaksi masuk. Kosongkan kedua tanggal untuk melihat seluruh riwayat.</p>
            </div>
        </form>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="section-title">Daftar transaksi</h2>
            <span class="badge text-bg-light"><?= count($transactions) ?> transaksi</span>
        </div>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>No. / ID Parkir</th>
                        <th>Plat Nomor & Kendaraan</th>
                        <th>Area Parkir</th>
                        <th>Waktu Masuk</th>
                        <th>Waktu Keluar</th>
                        <th>Durasi</th>
                        <th>Biaya Total</th>
                        <th>Status</th>
                        <th>Petugas</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transactions as $transaction): ?>
                    <tr>
                        <td class="fw-semibold">#<?= (int) $transaction['id_parkir'] ?></td>
                        <td>
                            <span class="fw-semibold"><?= e($transaction['plat_nomor']) ?></span>
                            <span class="d-block small text-muted"><?= e(ucfirst($transaction['jenis_kendaraan'])) ?></span>
                        </td>
                        <td><?= e($transaction['nama_area']) ?></td>
                        <td class="text-nowrap"><?= e($transaction['waktu_masuk']) ?></td>
                        <td class="text-nowrap"><?= e($transaction['waktu_keluar'] ?: '-') ?></td>
                        <td><?= $transaction['durasi_jam'] !== null ? (int) $transaction['durasi_jam'] . ' jam' : '-' ?></td>
                        <td class="text-nowrap"><?= $transaction['biaya_total'] !== null ? e(format_rupiah($transaction['biaya_total'])) : '-' ?></td>
                        <td>
                            <?php if ($transaction['status'] === 'masuk'): ?>
                                <span class="badge text-bg-success">Masuk</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Keluar</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($transaction['nama_petugas']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transactions): ?>
                    <tr>
                        <td colspan="9" class="table-empty">
                            <?= $error !== '' ? 'Tanggal filter perlu diperbaiki.' : ($hasDateFilter ? 'Tidak ada transaksi pada rentang tanggal tersebut.' : 'Belum ada riwayat transaksi.') ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
