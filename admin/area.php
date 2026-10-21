<?php
require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$pdo = db();
$errors = [];
$saved = ['nama_area' => '', 'kapasitas' => ''];

$loadAreas = static function () use ($pdo): array {
    $areas = $pdo->query(
        'SELECT id_area, nama_area, kapasitas
         FROM tb_area_parkir
         ORDER BY nama_area'
    )->fetchAll();
    
    $activeRows = $pdo->query(
        "SELECT t.id_area, t.id_parkir, t.waktu_masuk, k.plat_nomor, k.jenis_kendaraan, k.warna, k.pemilik,
                TIMESTAMPDIFF(MINUTE, t.waktu_masuk, NOW()) AS elapsed_minutes
         FROM tb_transaksi t
         JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
         WHERE t.status = 'masuk'
         ORDER BY t.id_area, t.id_parkir"
    )->fetchAll();

    $activeByArea = [];
    foreach ($activeRows as $row) {
        $mins = max(1, (int)$row['elapsed_minutes']);
        $jam = floor($mins / 60);
        $sisaMins = $mins % 60;
        $durasiStr = $jam > 0 ? "{$jam} Jam " . ($sisaMins > 0 ? "{$sisaMins} Mnt" : "") : "{$mins} Menit";

        $activeByArea[(int) $row['id_area']][] = [
            'id_parkir' => (int) $row['id_parkir'],
            'plat_nomor' => $row['plat_nomor'],
            'jenis_kendaraan' => ucfirst($row['jenis_kendaraan']),
            'warna' => $row['warna'],
            'pemilik' => $row['pemilik'],
            'waktu_masuk' => date('H:i', strtotime($row['waktu_masuk'])),
            'durasi' => $durasiStr,
        ];
    }

    foreach ($areas as &$area) {
        $areaId = (int) $area['id_area'];
        $area['kendaraan_aktif'] = $activeByArea[$areaId] ?? [];
        $area['terisi'] = count($area['kendaraan_aktif']);
    }
    unset($area);
    return $areas;
};

// Real-time Live Polling API
if (isset($_GET['live']) && $_GET['live'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    try {
        echo json_encode([
            'status' => 'success',
            'areas' => $loadAreas(),
            'timestamp' => date('H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('Gagal memuat status area parkir: ' . $exception->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'Status area belum dapat diperbarui.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$editingId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$editing = null;

if ($editingId > 0) {
    $editStatement = $pdo->prepare('SELECT id_area, nama_area, kapasitas, terisi FROM tb_area_parkir WHERE id_area = :id_area');
    $editStatement->execute(['id_area' => $editingId]);
    $editing = $editStatement->fetch();
    if (!$editing) {
        http_response_code(404);
        exit('Area parkir tidak ditemukan.');
    }
    $saved['nama_area'] = $editing['nama_area'];
    $saved['kapasitas'] = (string) $editing['kapasitas'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $recordId = filter_var($_POST['id_area'] ?? 0, FILTER_VALIDATE_INT);

    if ($action === 'delete' && $recordId && $recordId > 0) {
        try {
            $pdo->beginTransaction();
            $delete = $pdo->prepare('DELETE FROM tb_area_parkir WHERE id_area = :id_area');
            $delete->execute(['id_area' => $recordId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Area tidak ditemukan atau sudah dihapus.');
            }
            log_activity($pdo, (int) $_SESSION['id_user'], 'Hapus Area Parkir #' . $recordId);
            $pdo->commit();
            set_flash('success', 'Area parkir berhasil dihapus.');
            redirect('admin/area.php');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Gagal menghapus area parkir: ' . $exception->getMessage());
            $errors[] = 'Area tidak dapat dihapus karena masih digunakan oleh transaksi.';
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage();
        }
    } elseif (in_array($action, ['create', 'update'], true)) {
        $saved['nama_area'] = trim((string) ($_POST['nama_area'] ?? ''));
        $saved['kapasitas'] = trim((string) ($_POST['kapasitas'] ?? ''));
        $isUpdate = $action === 'update';
        $recordId = $isUpdate ? (int) $recordId : 0;

        if ($saved['nama_area'] === '') {
            $errors[] = 'Nama area tidak boleh kosong.';
        }
        if (!ctype_digit($saved['kapasitas']) || (int) $saved['kapasitas'] < 0) {
            $errors[] = 'Kapasitas harus berupa angka bulat positif.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($isUpdate) {
                    $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM tb_transaksi WHERE id_area = $recordId AND status = 'masuk'")->fetchColumn();
                    if ((int) $saved['kapasitas'] < $activeCount) {
                        throw new RuntimeException("Kapasitas tidak boleh lebih kecil dari kendaraan yang sedang parkir ($activeCount).");
                    }
                    $stmt = $pdo->prepare('UPDATE tb_area_parkir SET nama_area = :nama, kapasitas = :kap WHERE id_area = :id');
                    $stmt->execute(['nama' => $saved['nama_area'], 'kap' => (int) $saved['kapasitas'], 'id' => $recordId]);
                    log_activity($pdo, (int) $_SESSION['id_user'], 'Ubah Area Parkir #' . $recordId);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO tb_area_parkir (nama_area, kapasitas, terisi) VALUES (:nama, :kap, 0)');
                    $stmt->execute(['nama' => $saved['nama_area'], 'kap' => (int) $saved['kapasitas']]);
                    log_activity($pdo, (int) $_SESSION['id_user'], 'Tambah Area Parkir: ' . $saved['nama_area']);
                }
                $pdo->commit();
                set_flash('success', $isUpdate ? 'Area parkir berhasil diubah.' : 'Area parkir baru berhasil ditambahkan.');
                redirect('admin/area.php');
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $exception->getCode() === '23000'
                    ? 'Nama area sudah digunakan.'
                    : 'Area parkir gagal disimpan. Periksa kembali data.';
            } catch (RuntimeException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $exception->getMessage();
            }
        }
    }
}

$areas = $loadAreas();
$pageTitle = 'Area Parkir';
require __DIR__ . '/../includes/header.php';
?>

<!-- Monitoring area parkir secara langsung -->
<section class="card border-0 shadow-sm mb-4" style="border-radius: 24px; overflow: hidden;">
    <div class="card-body p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <div>
                <h1 class="h3 fw-bold text-dark m-0">Denah Slot Parkir Real-Time</h1>
                <p class="text-muted extra-small m-0">Petak berwarna menunjukkan proporsi slot terisi berdasarkan data terkini.</p>
            </div>
        </div>

        <div id="adminParkingGrid"></div>
    </div>
</section>

<?php if ($errors): ?>
    <div class="alert alert-danger alert-modern shadow-sm" role="alert">
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<!-- FORM TAMBAH / UBAH AREA PARKIR -->
<section class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <div class="d-flex align-items-center gap-2 mb-3">
            <span class="stat-icon <?= $editing ? 'stat-icon-amber' : 'stat-icon-emerald' ?>">
                <i class="bi <?= $editing ? 'bi-pencil-square' : 'bi-plus-lg' ?>"></i>
            </span>
            <div>
                <h3 class="fs-6 fw-bold text-dark m-0"><?= $editing ? 'Ubah Konfigurasi Area Parkir' : 'Tambah Area Parkir Baru' ?></h3>
                <p class="extra-small text-muted m-0"><?= $editing ? 'Kapasitas tidak dapat diturunkan di bawah slot yang sedang aktif terisi.' : 'Area baru akan langsung terhubung ke denah slot real-time.' ?></p>
            </div>
        </div>
        <form method="post" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <?php if ($editing): ?><input type="hidden" name="id_area" value="<?= (int) $editing['id_area'] ?>"><?php endif; ?>
            
            <div class="col-md-6">
                <label class="form-label extra-small fw-bold text-uppercase text-muted" for="nama_area">Nama Area</label>
                <input class="form-control" id="nama_area" name="nama_area" maxlength="50" value="<?= e($saved['nama_area']) ?>" placeholder="Contoh: Area A - Motor" required>
            </div>
            <div class="col-md-3">
                <label class="form-label extra-small fw-bold text-uppercase text-muted" for="kapasitas">Kapasitas Slot</label>
                <input class="form-control" id="kapasitas" name="kapasitas" type="number" min="<?= $editing ? (int) $editing['terisi'] : 1 ?>" step="1" value="<?= e($saved['kapasitas']) ?>" placeholder="Contoh: 50" required>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" type="submit">
                    <i class="bi bi-check2-circle me-1"></i><?= $editing ? 'Simpan Perubahan' : 'Tambah Area' ?>
                </button>
                <?php if ($editing): ?>
                    <a class="btn btn-light border" href="<?= e(app_url('admin/area.php')) ?>">Batal</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</section>

<!-- DAFTAR PENGATURAN AREA PARKIR -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
        <div>
            <h3 class="fs-6 fw-bold text-dark m-0">Daftar Area Terdaftar</h3>
            <p class="extra-small text-muted m-0">Kelola kapasitas kuota dan hak akses area</p>
        </div>
        <span class="badge bg-light text-secondary border"><?= count($areas) ?> Total Area</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Nama Area</th>
                    <th>Tipe Kendaraan</th>
                    <th>Total Kapasitas</th>
                    <th>Terisi</th>
                    <th>Tersedia</th>
                    <th>Persentase</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($areas): ?>
                    <?php foreach ($areas as $a): ?>
                        <?php 
                        $cap = (int) $a['kapasitas'];
                        $occ = (int) $a['terisi'];
                        $avail = max(0, $cap - $occ);
                        $pct = $cap > 0 ? round(($occ / $cap) * 100) : 0;
                        $nm = strtolower($a['nama_area']);
                        $isMotor = strpos($nm, 'motor') !== false || strpos($nm, 'bike') !== false;
                        ?>
                        <tr>
                            <td>
                                <strong class="d-block text-dark"><?= e($a['nama_area']) ?></strong>
                                <small class="text-muted extra-small">ID #<?= (int)$a['id_area'] ?></small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <i class="bi <?= $isMotor ? 'bi-bicycle text-danger' : 'bi-car-front-fill text-primary' ?> me-1"></i>
                                    <?= $isMotor ? 'Roda Dua (Motor)' : 'Roda Empat (Mobil/Lainnya)' ?>
                                </span>
                            </td>
                            <td class="fw-bold"><?= $cap ?> Slot</td>
                            <td class="text-danger fw-bold"><?= $occ ?></td>
                            <td class="text-emerald fw-bold"><?= $avail ?></td>
                            <td style="min-width: 140px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 6px;">
                                        <div class="progress-bar <?= $pct > 85 ? 'bg-danger' : 'bg-emerald' ?>" style="width: <?= $pct ?>%"></div>
                                    </div>
                                    <span class="extra-small fw-bold"><?= $pct ?>%</span>
                                </div>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light border py-1 px-2" href="<?= e(app_url('admin/area.php?edit=' . (int)$a['id_area'])) ?>">
                                    <i class="bi bi-pencil me-1"></i>Edit
                                </a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Hapus area <?= e($a['nama_area']) ?>? Data transaksi terkait akan diperiksa.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id_area" value="<?= (int)$a['id_area'] ?>">
                                    <button class="btn btn-sm btn-outline-danger py-1 px-2" type="submit">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted small">Belum ada area parkir terdaftar.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Script denah slot parkir -->
<script src="<?= e(app_url('assets/parking-grid.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const areasData = <?= json_encode($areas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const visualizer = new ParkingGridVisualizer('adminParkingGrid', {
        pollUrl: 'area.php?live=1',
        pollInterval: 8000,
        showRefreshButton: true
    });
    visualizer.setData(areasData);
    visualizer.startPolling();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
