<?php
require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$pdo = db();
$search = trim((string) ($_GET['q'] ?? ''));
$perPage = 20;
$totalStatement = $pdo->prepare(
    "SELECT COUNT(*) FROM tb_kendaraan k
     WHERE (:search = '' OR k.plat_nomor LIKE :plate_search OR k.pemilik LIKE :owner_search)"
);
$likeSearch = '%' . $search . '%';
$totalStatement->execute([
    'search' => $search,
    'plate_search' => $likeSearch,
    'owner_search' => $likeSearch,
]);
$totalRecords = (int) $totalStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));
$currentPage = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));
$offset = ($currentPage - 1) * $perPage;

$statement = $pdo->prepare(
    "SELECT k.id_kendaraan, k.plat_nomor, k.jenis_kendaraan, k.warna, k.pemilik,
            u.nama_lengkap AS pencatat,
            EXISTS (
                SELECT 1
                FROM tb_transaksi t
                WHERE t.id_kendaraan = k.id_kendaraan
                  AND t.status = 'masuk'
            ) AS sedang_parkir
     FROM tb_kendaraan k
     LEFT JOIN tb_user u ON u.id_user = k.id_user
     WHERE (:search = '' OR k.plat_nomor LIKE :plate_search OR k.pemilik LIKE :owner_search)
     ORDER BY k.id_kendaraan DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$statement->execute([
    'search' => $search,
    'plate_search' => $likeSearch,
    'owner_search' => $likeSearch,
]);
$vehicles = $statement->fetchAll();
$pageTitle = 'Data Kendaraan';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div><h1>Data Kendaraan</h1><p>Pantau kendaraan terdaftar dan status parkir aktifnya.</p></div>
    <span class="badge text-bg-light"><?= $totalRecords ?> kendaraan</span>
</div>

<section class="card">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h2 class="section-title">Daftar kendaraan</h2>
                <p class="small text-muted mb-0 mt-1">Data kendaraan dikelola Petugas saat transaksi parkir masuk.</p>
            </div>
            <form method="get" class="d-flex gap-2" role="search">
                <label class="visually-hidden" for="vehicleSearch">Cari plat nomor atau nama pemilik</label>
                <input class="form-control" id="vehicleSearch" name="q" type="search" value="<?= e($search) ?>" placeholder="Plat nomor atau pemilik">
                <button class="btn btn-primary text-nowrap" type="submit"><i class="bi bi-search me-1"></i>Cari</button>
                <?php if ($search !== ''): ?><a class="btn btn-outline-secondary" href="<?= e(app_url('admin/kendaraan.php')) ?>">Reset</a><?php endif; ?>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr><th>ID</th><th>Plat nomor</th><th>Jenis</th><th>Warna</th><th>Pemilik</th><th>Dicatat oleh</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($vehicles as $vehicle): ?>
                    <tr>
                        <td><?= (int) $vehicle['id_kendaraan'] ?></td>
                        <td class="fw-semibold"><?= e($vehicle['plat_nomor']) ?></td>
                        <td><?= e(ucfirst($vehicle['jenis_kendaraan'])) ?></td>
                        <td><?= e($vehicle['warna']) ?></td>
                        <td><?= e($vehicle['pemilik']) ?></td>
                        <td><?= e($vehicle['pencatat'] ?: '-') ?></td>
                        <td>
                            <?php if ((int) $vehicle['sedang_parkir'] === 1): ?>
                                <span class="badge text-bg-success"><i class="bi bi-p-circle-fill me-1"></i>Sedang Parkir</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary"><i class="bi bi-box-arrow-right me-1"></i>Keluar</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$vehicles): ?>
                    <tr><td colspan="7" class="table-empty"><?= $search !== '' ? 'Tidak ada kendaraan yang cocok dengan pencarian.' : 'Belum ada kendaraan terdaftar.' ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1):
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $currentPage + 2);
            $pageUrl = function ($page) use ($search) {
                return app_url('admin/kendaraan.php?' . http_build_query(['q' => $search, 'page' => $page]));
            };
            ?>
            <nav class="mt-3" aria-label="Halaman data kendaraan">
                <ul class="pagination pagination-sm justify-content-end mb-0">
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(max(1, $currentPage - 1))) ?>" aria-label="Sebelumnya"><i class="bi bi-chevron-left"></i></a></li>
                    <?php if ($startPage > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?= e($pageUrl(1)) ?>">1</a></li>
                        <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                        <li class="page-item <?= $page === $currentPage ? 'active' : '' ?>"><a class="page-link" href="<?= e($pageUrl($page)) ?>"><?= $page ?></a></li>
                    <?php endfor; ?>
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= e($pageUrl($totalPages)) ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($pageUrl(min($totalPages, $currentPage + 1))) ?>" aria-label="Berikutnya"><i class="bi bi-chevron-right"></i></a></li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
