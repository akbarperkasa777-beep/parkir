<?php
require_once __DIR__ . '/../includes/app.php';
require_role('petugas');

$pdo = db();
$errors = [];
$vehicleMode = 'existing';
$selectedVehicleId = '';
$selectedAreaId = '';
$newVehicle = [
    'plat_nomor' => '',
    'jenis_kendaraan' => '',
    'warna' => '',
    'pemilik' => '',
];

// AJAX Live Polling for Petugas
if (isset($_GET['live']) && $_GET['live'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    try {
        $areas = $pdo->query('SELECT id_area, nama_area, kapasitas FROM tb_area_parkir ORDER BY nama_area')->fetchAll();
        $activeRows = $pdo->query(
            "SELECT t.id_area, t.id_parkir, t.waktu_masuk, k.plat_nomor, k.jenis_kendaraan, k.warna, k.pemilik,
                    TIMESTAMPDIFF(MINUTE, t.waktu_masuk, NOW()) AS elapsed_minutes
             FROM tb_transaksi t
             JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
             WHERE t.status = 'masuk'
             ORDER BY t.id_area, t.id_parkir"
        )->fetchAll();

        $vehiclesByArea = [];
        foreach ($activeRows as $activeRow) {
            $mins = max(1, (int)$activeRow['elapsed_minutes']);
            $jam = floor($mins / 60);
            $sisaMins = $mins % 60;
            $durasiStr = $jam > 0 ? "{$jam} Jam " . ($sisaMins > 0 ? "{$sisaMins} Mnt" : "") : "{$mins} Menit";

            $vehiclesByArea[(int) $activeRow['id_area']][] = [
                'id_parkir' => (int) $activeRow['id_parkir'],
                'plat_nomor' => $activeRow['plat_nomor'],
                'jenis_kendaraan' => ucfirst($activeRow['jenis_kendaraan']),
                'warna' => $activeRow['warna'],
                'pemilik' => $activeRow['pemilik'],
                'waktu_masuk' => date('H:i', strtotime($activeRow['waktu_masuk'])),
                'durasi' => $durasiStr,
            ];
        }

        foreach ($areas as &$area) {
            $area['id_area'] = (int) $area['id_area'];
            $area['kapasitas'] = (int) $area['kapasitas'];
            $area['kendaraan_aktif'] = $vehiclesByArea[$area['id_area']] ?? [];
            $area['terisi'] = count($area['kendaraan_aktif']);
        }
        unset($area);

        $tariffs = $pdo->query('SELECT jenis_kendaraan, tarif_per_jam FROM tb_tarif ORDER BY jenis_kendaraan')->fetchAll();
        foreach ($tariffs as &$tariff) {
            $tariff['tarif_per_jam'] = (float) $tariff['tarif_per_jam'];
        }
        unset($tariff);

        echo json_encode([
            'status' => 'success',
            'areas' => $areas,
            'tariffs' => $tariffs,
            'timestamp' => date('H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        error_log('Gagal memuat status langsung parkir masuk: ' . $exception->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'Status area dan tarif belum dapat diperbarui.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $vehicleMode = (string) ($_POST['vehicle_mode'] ?? 'existing');
    $selectedVehicleId = (string) ($_POST['id_kendaraan'] ?? '');
    $selectedAreaId = (string) ($_POST['id_area'] ?? '');
    $vehicleId = filter_var($selectedVehicleId, FILTER_VALIDATE_INT);
    $areaId = filter_var($selectedAreaId, FILTER_VALIDATE_INT);
    $newVehicle['plat_nomor'] = strtoupper(trim((string) ($_POST['plat_nomor'] ?? '')));
    $newVehicle['jenis_kendaraan'] = strtolower(trim((string) ($_POST['jenis_kendaraan'] ?? '')));
    $newVehicle['warna'] = trim((string) ($_POST['warna'] ?? ''));
    $newVehicle['pemilik'] = trim((string) ($_POST['pemilik'] ?? ''));

    if (!in_array($vehicleMode, ['existing', 'new'], true)) {
        $errors[] = 'Pilihan pendaftaran kendaraan tidak valid.';
    }
    if (!$areaId || $areaId < 1) {
        $errors[] = 'Area parkir wajib dipilih.';
    }
    if ($vehicleMode === 'existing' && (!$vehicleId || $vehicleId < 1)) {
        $errors[] = 'Kendaraan terdaftar wajib dipilih.';
    }
    if ($vehicleMode === 'new') {
        if ($newVehicle['plat_nomor'] === '' || strlen($newVehicle['plat_nomor']) > 15) {
            $errors[] = 'Plat nomor wajib diisi dan maksimal 15 karakter.';
        }
        if ($newVehicle['jenis_kendaraan'] === '' || strlen($newVehicle['jenis_kendaraan']) > 20) {
            $errors[] = 'Jenis kendaraan wajib diisi dan maksimal 20 karakter.';
        }
        if ($newVehicle['warna'] === '' || strlen($newVehicle['warna']) > 20) {
            $errors[] = 'Warna kendaraan wajib diisi dan maksimal 20 karakter.';
        }
        if ($newVehicle['pemilik'] === '' || strlen($newVehicle['pemilik']) > 100) {
            $errors[] = 'Nama pemilik kendaraan wajib diisi dan maksimal 100 karakter.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $areaStatement = $pdo->prepare('SELECT id_area, nama_area, kapasitas, terisi FROM tb_area_parkir WHERE id_area = :id_area FOR UPDATE');
            $areaStatement->execute(['id_area' => $areaId]);
            $area = $areaStatement->fetch();
            if (!$area) {
                throw new RuntimeException('Area parkir tidak ditemukan.');
            }
            if ((int) $area['terisi'] >= (int) $area['kapasitas']) {
                throw new RuntimeException('Area parkir sudah penuh.');
            }

            if ($vehicleMode === 'existing') {
                $vehicleStatement = $pdo->prepare('SELECT id_kendaraan, plat_nomor, jenis_kendaraan FROM tb_kendaraan WHERE id_kendaraan = :id_kendaraan');
                $vehicleStatement->execute(['id_kendaraan' => $vehicleId]);
                $vehicle = $vehicleStatement->fetch();
                if (!$vehicle) {
                    throw new RuntimeException('Kendaraan tidak ditemukan.');
                }
                $activeCheck = $pdo->prepare("SELECT 1 FROM tb_transaksi WHERE id_kendaraan = :id_kendaraan AND status = 'masuk'");
                $activeCheck->execute(['id_kendaraan' => $vehicleId]);
                if ($activeCheck->fetch()) {
                    throw new RuntimeException('Kendaraan tersebut sedang berada di dalam area parkir.');
                }
            } else {
                $duplicateCheck = $pdo->prepare('SELECT id_kendaraan FROM tb_kendaraan WHERE plat_nomor = :plat_nomor');
                $duplicateCheck->execute(['plat_nomor' => $newVehicle['plat_nomor']]);
                if ($duplicateCheck->fetch()) {
                    throw new RuntimeException('Plat nomor sudah terdaftar. Silakan pilih dari kendaraan terdaftar.');
                }
                $insertVehicle = $pdo->prepare(
                    'INSERT INTO tb_kendaraan (plat_nomor, jenis_kendaraan, warna, pemilik, id_user)
                     VALUES (:plat_nomor, :jenis_kendaraan, :warna, :pemilik, :id_user)'
                );
                $insertVehicle->execute([
                    'plat_nomor' => $newVehicle['plat_nomor'],
                    'jenis_kendaraan' => $newVehicle['jenis_kendaraan'],
                    'warna' => $newVehicle['warna'],
                    'pemilik' => $newVehicle['pemilik'],
                    'id_user' => (int) $_SESSION['id_user'],
                ]);
                $vehicleId = (int) $pdo->lastInsertId();
                $vehicle = [
                    'id_kendaraan' => $vehicleId,
                    'plat_nomor' => $newVehicle['plat_nomor'],
                    'jenis_kendaraan' => $newVehicle['jenis_kendaraan'],
                ];
            }

            $tariffStatement = $pdo->prepare('SELECT id_tarif, tarif_per_jam FROM tb_tarif WHERE jenis_kendaraan = :jenis_kendaraan');
            $tariffStatement->execute(['jenis_kendaraan' => $vehicle['jenis_kendaraan']]);
            $tariff = $tariffStatement->fetch();
            if (!$tariff) {
                throw new RuntimeException('Tarif untuk jenis kendaraan ' . $vehicle['jenis_kendaraan'] . ' belum diatur.');
            }

            $insertTransaction = $pdo->prepare(
                'INSERT INTO tb_transaksi (id_kendaraan, waktu_masuk, id_tarif, status, id_user, id_area)
                 VALUES (:id_kendaraan, NOW(), :id_tarif, :status, :id_user, :id_area)'
            );
            $insertTransaction->execute([
                'id_kendaraan' => $vehicleId,
                'id_tarif' => (int) $tariff['id_tarif'],
                'status' => 'masuk',
                'id_user' => (int) $_SESSION['id_user'],
                'id_area' => $areaId,
            ]);
            $parkingId = (int) $pdo->lastInsertId();

            $updateArea = $pdo->prepare('UPDATE tb_area_parkir SET terisi = terisi + 1 WHERE id_area = :id_area');
            $updateArea->execute(['id_area' => $areaId]);

            log_activity($pdo, (int) $_SESSION['id_user'], 'Transaksi parkir masuk #' . $parkingId . ' (' . $vehicle['plat_nomor'] . ')');
            $pdo->commit();
            redirect('petugas/cetak_struk.php?id=' . $parkingId);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Transaksi parkir masuk gagal: ' . $exception->getMessage());
            $errors[] = 'Transaksi gagal disimpan. Periksa data kendaraan dan area.';
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage();
        }
    }
}

$vehicles = $pdo->query(
    "SELECT k.id_kendaraan, k.plat_nomor, k.jenis_kendaraan, k.pemilik,
            EXISTS (
                SELECT 1 FROM tb_transaksi t
                WHERE t.id_kendaraan = k.id_kendaraan AND t.status = 'masuk'
            ) AS sedang_parkir
     FROM tb_kendaraan k
     ORDER BY k.plat_nomor"
)->fetchAll();

$areas = $pdo->query('SELECT id_area, nama_area, kapasitas FROM tb_area_parkir ORDER BY nama_area')->fetchAll();
$activeRows = $pdo->query(
    "SELECT t.id_area, t.id_parkir, t.waktu_masuk, k.plat_nomor, k.jenis_kendaraan, k.warna, k.pemilik,
            TIMESTAMPDIFF(MINUTE, t.waktu_masuk, NOW()) AS elapsed_minutes
     FROM tb_transaksi t
     JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
     WHERE t.status = 'masuk'
     ORDER BY t.id_area, t.id_parkir"
)->fetchAll();

$vehiclesByArea = [];
foreach ($activeRows as $activeRow) {
    $mins = max(1, (int)$activeRow['elapsed_minutes']);
    $jam = floor($mins / 60);
    $sisaMins = $mins % 60;
    $durasiStr = $jam > 0 ? "{$jam} Jam " . ($sisaMins > 0 ? "{$sisaMins} Mnt" : "") : "{$mins} Menit";

    $vehiclesByArea[(int) $activeRow['id_area']][] = [
        'id_parkir' => (int) $activeRow['id_parkir'],
        'plat_nomor' => $activeRow['plat_nomor'],
        'jenis_kendaraan' => ucfirst($activeRow['jenis_kendaraan']),
        'warna' => $activeRow['warna'],
        'pemilik' => $activeRow['pemilik'],
        'waktu_masuk' => date('H:i', strtotime($activeRow['waktu_masuk'])),
        'durasi' => $durasiStr,
    ];
}

foreach ($areas as &$area) {
    $area['id_area'] = (int) $area['id_area'];
    $area['kapasitas'] = (int) $area['kapasitas'];
    $area['kendaraan_aktif'] = $vehiclesByArea[$area['id_area']] ?? [];
    $area['terisi'] = count($area['kendaraan_aktif']);
}
unset($area);

$tariffs = $pdo->query('SELECT jenis_kendaraan, tarif_per_jam FROM tb_tarif ORDER BY jenis_kendaraan')->fetchAll();
foreach ($tariffs as &$tariff) {
    $tariff['tarif_per_jam'] = (float) $tariff['tarif_per_jam'];
}
unset($tariff);

$liveData = ['areas' => $areas, 'tariffs' => $tariffs];

$active = $pdo->query(
    "SELECT t.id_parkir, t.waktu_masuk, k.plat_nomor, k.jenis_kendaraan, a.nama_area 
     FROM tb_transaksi t 
     JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan 
     JOIN tb_area_parkir a ON a.id_area = t.id_area 
     WHERE t.status = 'masuk' 
     ORDER BY t.waktu_masuk DESC LIMIT 10"
)->fetchAll();

$totalSlots = (int) $pdo->query('SELECT COALESCE(SUM(kapasitas), 0) FROM tb_area_parkir')->fetchColumn();
$occupiedSlots = (int) $pdo->query('SELECT COALESCE(SUM(terisi), 0) FROM tb_area_parkir')->fetchColumn();
$todayTx = (int) $pdo->query('SELECT COUNT(*) FROM tb_transaksi WHERE waktu_masuk >= CURDATE() AND waktu_masuk < CURDATE() + INTERVAL 1 DAY')->fetchColumn();
$todayRev = (float) $pdo->query("SELECT COALESCE(SUM(biaya_total), 0) FROM tb_transaksi WHERE status = 'keluar' AND waktu_keluar >= CURDATE() AND waktu_keluar < CURDATE() + INTERVAL 1 DAY")->fetchColumn();

if ($selectedAreaId === '') {
    foreach ($areas as $area) {
        if ((int) $area['terisi'] < (int) $area['kapasitas']) {
            $selectedAreaId = (string) $area['id_area'];
            break;
        }
    }
}

$pageTitle = 'Transaksi Parkir Masuk';
require __DIR__ . '/../includes/header.php';
?>

<!-- HEADER WITH TITLE -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <div>
        <h1 class="h3 fw-bold text-dark m-0">Transaksi Parkir Masuk</h1>
        <p class="text-muted extra-small m-0">Catat pendaftaran kendaraan dan pilih slot pada denah area parkir.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-emerald-subtle text-emerald border border-emerald-subtle px-3 py-1">
            <i class="bi bi-shield-check me-1"></i>POS Petugas Aktif
        </span>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger alert-modern shadow-sm" role="alert">
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<!-- 4 SUMMARY WIDGET STATS -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern py-3">
            <div class="stat-header mb-1">
                <div>
                    <span class="stat-kicker">Total Slot Kapasitas</span>
                    <div class="stat-number text-dark fs-4"><?= $totalSlots ?> <small class="fs-6 text-muted fw-normal">Slot</small></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-red"><i class="bi bi-p-square"></i></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern py-3">
            <div class="stat-header mb-1">
                <div>
                    <span class="stat-kicker">Slot Terisi Saat Ini</span>
                    <div class="stat-number text-danger fs-4"><?= $occupiedSlots ?> <small class="fs-6 text-muted fw-normal">Unit</small></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-amber"><i class="bi bi-car-front-fill"></i></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern py-3">
            <div class="stat-header mb-1">
                <div>
                    <span class="stat-kicker">Transaksi Hari Ini</span>
                    <div class="stat-number text-primary fs-4"><?= $todayTx ?> <small class="fs-6 text-muted fw-normal">Unit</small></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-sky"><i class="bi bi-arrow-left-right"></i></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card-modern py-3">
            <div class="stat-header mb-1">
                <div>
                    <span class="stat-kicker">Pendapatan Hari Ini</span>
                    <div class="stat-number text-emerald fs-4"><?= format_rupiah($todayRev) ?></div>
                </div>
                <div class="stat-icon-wrapper stat-icon-emerald"><i class="bi bi-wallet2"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================
     DUAL-COLUMN: FORM KIRI & DENAH SLOT KANAN
     ======================================================== -->
<form method="post" class="row g-4 align-items-start entry-layout" id="formEntryParkir">
    <?= csrf_field() ?>

    <!-- KOLOM KIRI: FORMULIR INPUT KENDARAAN -->
    <div class="col-lg-5">
        <section class="card border-0 shadow-sm h-100" style="border-radius: 20px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="stat-icon stat-icon-emerald"><i class="bi bi-box-arrow-in-down-left"></i></span>
                    <div>
                        <h2 class="fs-6 fw-bold text-dark m-0">Formulir Tiket Masuk</h2>
                        <p class="extra-small text-muted m-0">Pilih kendaraan terdaftar atau daftarkan baru.</p>
                    </div>
                </div>

                <!-- Notifikasi slot yang dipilih dari denah -->
                <div id="slotSelectionBanner" class="alert alert-info py-2 px-3 small d-flex align-items-center justify-content-between mb-3 d-none">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-geo-alt-fill text-primary fs-5"></i>
                        <span>Slot Terpilih: <strong id="selectedSlotPillText" class="text-dark font-monospace">#A01</strong> (<span id="selectedSlotAreaText">Area</span>)</span>
                    </div>
                    <button type="button" class="btn-close btn-sm" onclick="clearSelectedSlot()"></button>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label extra-small fw-bold text-uppercase text-muted" for="vehicle_mode">Pendaftaran Kendaraan</label>
                        <select class="form-select" name="vehicle_mode" id="vehicle_mode">
                            <option value="existing" <?= $vehicleMode === 'existing' ? 'selected' : '' ?>>Kendaraan Terdaftar</option>
                            <option value="new" <?= $vehicleMode === 'new' ? 'selected' : '' ?>>Daftarkan Kendaraan Baru</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label extra-small fw-bold text-uppercase text-muted" for="id_area">Area Parkir Tujuan</label>
                        <select class="form-select" name="id_area" id="id_area" required>
                            <option value="">Pilih area parkir</option>
                            <?php foreach ($areas as $area):
                                $areaAvailable = max(0, (int) $area['kapasitas'] - (int) $area['terisi']);
                                ?>
                                <option value="<?= (int) $area['id_area'] ?>" <?= $areaAvailable < 1 ? 'disabled' : '' ?> <?= $selectedAreaId === (string) $area['id_area'] ? 'selected' : '' ?>>
                                    <?= e($area['nama_area'] . ' (Sisa ' . $areaAvailable . ' slot)') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Input Kendaraan Terdaftar -->
                    <div class="col-12" id="existingVehicleFields" <?= $vehicleMode === 'new' ? 'hidden' : '' ?>>
                        <label class="form-label extra-small fw-bold text-uppercase text-muted" for="id_kendaraan">Pilih Kendaraan Terdaftar</label>
                        <select class="form-select" name="id_kendaraan" id="id_kendaraan" <?= $vehicleMode === 'existing' ? 'required' : 'disabled' ?>>
                            <option value="">Cari nomor plat kendaraan</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?= (int) $vehicle['id_kendaraan'] ?>" data-type="<?= e(strtolower($vehicle['jenis_kendaraan'])) ?>" <?= (int) $vehicle['sedang_parkir'] === 1 ? 'disabled' : '' ?> <?= (string) $vehicle['id_kendaraan'] === $selectedVehicleId ? 'selected' : '' ?>>
                                    <?= e($vehicle['plat_nomor'] . ' - ' . ucfirst($vehicle['jenis_kendaraan']) . ' (' . $vehicle['pemilik'] . ')' . ((int) $vehicle['sedang_parkir'] === 1 ? ' [Sedang Parkir]' : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Input Kendaraan Baru -->
                    <fieldset class="col-12" id="newVehicleFields" <?= $vehicleMode === 'new' ? '' : 'hidden' ?>>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label extra-small fw-bold text-uppercase text-muted" for="plat_nomor">Nomor Plat</label>
                                <input class="form-control plate-input-sm font-monospace text-uppercase" id="plat_nomor" name="plat_nomor" maxlength="15" value="<?= e($newVehicle['plat_nomor']) ?>" placeholder="B 1234 ABC" <?= $vehicleMode === 'new' ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label extra-small fw-bold text-uppercase text-muted" for="jenis_kendaraan">Jenis Kendaraan</label>
                                <input class="form-control" id="jenis_kendaraan" name="jenis_kendaraan" list="vehicleTypeSuggestions" maxlength="20" value="<?= e($newVehicle['jenis_kendaraan']) ?>" placeholder="Pilih / ketik jenis" <?= $vehicleMode === 'new' ? 'required' : 'disabled' ?>>
                                <datalist id="vehicleTypeSuggestions">
                                    <?php foreach ($tariffs as $tariff): ?><option value="<?= e($tariff['jenis_kendaraan']) ?>"><?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label extra-small fw-bold text-uppercase text-muted" for="warna">Warna</label>
                                <input class="form-control" id="warna" name="warna" maxlength="20" value="<?= e($newVehicle['warna']) ?>" placeholder="Hitam" <?= $vehicleMode === 'new' ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label extra-small fw-bold text-uppercase text-muted" for="pemilik">Nama Pemilik</label>
                                <input class="form-control" id="pemilik" name="pemilik" maxlength="100" value="<?= e($newVehicle['pemilik']) ?>" placeholder="Budi" <?= $vehicleMode === 'new' ? 'required' : 'disabled' ?>>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Widget Live Tarif Aktif -->
                    <div class="col-12">
                        <section class="live-tariff-card py-2 px-3 border rounded-3 bg-light">
                            <span class="live-tariff-icon stat-icon-emerald me-2"><i class="bi bi-receipt-cutoff"></i></span>
                            <div class="flex-grow-1">
                                <span class="extra-small text-muted fw-bold text-uppercase d-block">Tarif Parkir Terpilih</span>
                                <strong id="activeTariffValue" class="fs-6 text-dark">Pilih kendaraan</strong>
                                <small id="activeTariffCaption" class="extra-small text-muted d-block">Tarif per jam mengikuti jenis kendaraan.</small>
                            </div>
                            <span class="badge bg-secondary extra-small" id="activeTariffStatus">Menunggu</span>
                        </section>
                    </div>

                    <div class="col-12 mt-3">
                        <button class="btn btn-emerald btn-lg w-100 py-3 d-flex align-items-center justify-content-center gap-2" type="submit">
                            <i class="bi bi-printer-fill fs-5"></i>
                            <span class="fs-6">Simpan & Cetak Tiket Parkir (Enter)</span>
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- KOLOM KANAN: DENAH SLOT PARKIR REAL-TIME -->
    <div class="col-lg-7">
        <section class="card border-0 shadow-sm petugas-parking-card">
            <div class="card-header bg-white p-3 border-bottom">
                <h2 class="fs-6 fw-bold text-dark mb-1">Denah Slot Parkir Real-Time</h2>
                <p class="small text-muted mb-0">Pilih area, lalu klik petak hijau untuk memilih slot dan mengisi area tujuan.</p>
            </div>

            <!-- Viewport Container -->
            <div class="p-2 p-md-3 bg-light">
                <div id="petugasParkingGrid"></div>
            </div>
        </section>
    </div>
</form>

<!-- KENDARAAN SEDANG PARKIR (LATEST TRANSACTIONS) -->
<div class="card border-0 shadow-sm mt-4" style="border-radius: 20px;">
    <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <span class="stat-icon stat-icon-amber p-1" style="width: 32px; height: 32px; font-size: 1rem;"><i class="bi bi-car-front"></i></span>
            <h2 class="fs-6 fw-bold text-dark m-0">Kendaraan Sedang Parkir Aktif</h2>
        </div>
        <span class="badge bg-light text-secondary border"><?= count($active) ?> Terdata</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>No. Tiket</th>
                    <th>Plat Nomor</th>
                    <th>Jenis Kendaraan</th>
                    <th>Area Parkir</th>
                    <th>Waktu Masuk</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($active): ?>
                    <?php foreach ($active as $tx): ?>
                        <tr>
                            <td><span class="badge bg-light text-secondary border font-monospace">#<?= (int)$tx['id_parkir'] ?></span></td>
                            <td><strong class="font-monospace text-dark"><?= e($tx['plat_nomor']) ?></strong></td>
                            <td class="small"><?= e(ucfirst($tx['jenis_kendaraan'] ?? 'Kendaraan')) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($tx['nama_area']) ?></span></td>
                            <td class="extra-small text-muted"><?= e(date('d M Y, H:i', strtotime($tx['waktu_masuk']))) ?> WIB</td>
                            <td><span class="badge badge-masuk">Sedang Parkir</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted small">Tidak ada kendaraan di dalam area parkir.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SCRIPT: ISO PARKING VISUALIZER & FORM CONNECTOR -->
<script src="<?= e(app_url('assets/parking-grid.js')) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var mode = document.getElementById('vehicle_mode');
    var existingFields = document.getElementById('existingVehicleFields');
    var newFields = document.getElementById('newVehicleFields');
    var existingInputs = existingFields.querySelectorAll('input, select');
    var newInputs = newFields.querySelectorAll('input, select');
    var existingVehicle = document.getElementById('id_kendaraan');
    var newVehicleType = document.getElementById('jenis_kendaraan');
    var areaSelect = document.getElementById('id_area');
    var tariffValue = document.getElementById('activeTariffValue');
    var tariffCaption = document.getElementById('activeTariffCaption');
    var tariffStatus = document.getElementById('activeTariffStatus');
    var submitButton = document.querySelector('.entry-layout button[type="submit"]');

    var liveData = <?= json_encode($liveData, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var areaById = {};
    var tariffsByType = {};

    function indexData() {
        areaById = {};
        (liveData.areas || []).forEach(a => areaById[a.id_area] = a);
        tariffsByType = {};
        (liveData.tariffs || []).forEach(t => tariffsByType[(t.jenis_kendaraan || '').toLowerCase()] = t);
    }
    indexData();

    function activeVehicleType() {
        if (mode.value === 'new') {
            return (newVehicleType.value || '').trim().toLowerCase();
        }
        var opt = existingVehicle.options[existingVehicle.selectedIndex];
        return opt ? (opt.getAttribute('data-type') || '').trim().toLowerCase() : '';
    }

    function updateSubmitState() {
        var area = areaById[areaSelect.value];
        var available = area ? Number(area.kapasitas) - Number(area.terisi) : 0;
        var hasTariff = Boolean(tariffsByType[activeVehicleType()]);
        submitButton.disabled = !area || available < 1 || !hasTariff;
    }

    function updateTariff() {
        var type = activeVehicleType();
        var tariff = tariffsByType[type];
        if (!type) {
            tariffValue.textContent = 'Pilih kendaraan';
            tariffCaption.textContent = 'Tarif per jam mengikuti jenis kendaraan.';
            tariffStatus.textContent = 'Menunggu';
            tariffStatus.className = 'badge bg-secondary extra-small';
            updateSubmitState();
            return;
        }
        if (!tariff) {
            tariffValue.textContent = 'Tarif belum diatur';
            tariffCaption.textContent = 'Admin perlu menambahkan tarif untuk tipe ini.';
            tariffStatus.textContent = 'Tidak Valid';
            tariffStatus.className = 'badge bg-danger extra-small';
            updateSubmitState();
            return;
        }
        tariffValue.textContent = 'Rp ' + Number(tariff.tarif_per_jam).toLocaleString('id-ID') + ' / jam';
        tariffCaption.textContent = 'Dibulatkan per jam berikutnya.';
        tariffStatus.textContent = 'Aktif';
        tariffStatus.className = 'badge bg-success extra-small';
        updateSubmitState();
    }

    mode.addEventListener('change', function() {
        var isNew = mode.value === 'new';
        existingFields.hidden = isNew;
        newFields.hidden = !isNew;
        existingInputs.forEach(i => i.disabled = isNew);
        newInputs.forEach(i => i.disabled = !isNew);
        updateTariff();
    });

    existingVehicle.addEventListener('change', updateTariff);
    newVehicleType.addEventListener('input', updateTariff);
    areaSelect.addEventListener('change', function() {
        updateSubmitState();
        if (visualizer) visualizer.setActiveArea(this.value);
    });

    // Flat 2D slot map and form connector.
    const visualizer = new ParkingGridVisualizer('petugasParkingGrid', {
        pollUrl: 'transaksi_masuk.php?live=1',
        pollInterval: 8000,
        isPetugasMode: true,
        activeAreaId: Number(areaSelect.value) || 0,
        onAreaChange: function(areaId) {
            if (areaId > 0) areaSelect.value = String(areaId);
            updateSubmitState();
        },
        onSlotClick: function(slotData) {
            // Interactive Click-to-Select:
            areaSelect.value = String(slotData.areaId);
            updateSubmitState();

            // Display banner
            const banner = document.getElementById('slotSelectionBanner');
            document.getElementById('selectedSlotPillText').textContent = '#' + slotData.slotNum;
            document.getElementById('selectedSlotAreaText').textContent = slotData.areaName;
            banner.classList.remove('d-none');

            // Auto-focus on plate input
            if (mode.value === 'new') {
                document.getElementById('plat_nomor').focus();
            } else {
                document.getElementById('id_kendaraan').focus();
            }
        }
    });

    visualizer.setData(liveData.areas);
    visualizer.startPolling();

    window.clearSelectedSlot = function() {
        document.getElementById('slotSelectionBanner').classList.add('d-none');
        visualizer.clearSelection();
    };

    updateTariff();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
