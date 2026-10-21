<?php
require_once __DIR__ . '/../includes/app.php';
require_role('petugas');
$pdo = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $parkingId = filter_input(INPUT_POST, 'id_parkir', FILTER_VALIDATE_INT);
    if (!$parkingId || $parkingId < 1) {
        $error = 'Nomor transaksi tidak valid.';
    } else {
        try {
            $statement = $pdo->prepare('CALL sp_parkir_keluar(:id_parkir, :id_user)');
            $statement->execute([
                'id_parkir' => $parkingId,
                'id_user' => (int) $_SESSION['id_user'],
            ]);
            $payment = $statement->fetch();
            $statement->closeCursor();
            if (!$payment) {
                throw new RuntimeException('Prosedur tidak mengembalikan data pembayaran.');
            }
            redirect('petugas/cetak_struk.php?id=' . (int) $payment['id_parkir']);
        } catch (PDOException $exception) {
            error_log('Transaksi parkir keluar gagal: ' . $exception->getMessage());
            $error = 'Transaksi keluar gagal. Pastikan kendaraan masih berstatus masuk dan prosedur parkir keluar telah terpasang.';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }
}

$transactions = $pdo->query(
    "SELECT t.id_parkir, t.waktu_masuk, k.plat_nomor, k.jenis_kendaraan, a.nama_area
     FROM tb_transaksi t
     JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
     JOIN tb_area_parkir a ON a.id_area = t.id_area
     WHERE t.status = 'masuk'
     ORDER BY t.waktu_masuk"
)->fetchAll();
$pageTitle = 'Transaksi Keluar';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <h1>Transaksi Parkir Keluar</h1>
    <p>Pindai QR pada struk atau masukkan nomor tiket/plat secara manual, lalu konfirmasi pembayaran.</p>
</div>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-xl-5">
        <section class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="stat-icon orange"><i class="bi bi-qr-code-scan"></i></span>
                    <h2 class="section-title">Pindai atau cari tiket</h2>
                </div>
                <p class="small text-muted">QR Code berisi nomor tiket seperti <strong>ID-PARKIR-123</strong>. Pemindaian hanya memilih transaksi; Petugas tetap perlu mengonfirmasi proses keluar.</p>
                <div id="reader" class="qr-reader mb-3" aria-label="Area kamera untuk pemindaian QR Code"></div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button class="btn btn-primary" type="button" id="cameraButton"><i class="bi bi-camera-video me-1"></i>Buka Kamera / Scan QR Code</button>
                    <button class="btn btn-outline-secondary" type="button" id="stopCameraButton" hidden><i class="bi bi-camera-video-off me-1"></i>Hentikan kamera</button>
                </div>
                <div class="small text-muted mb-3" id="scannerMessage" role="status" aria-live="polite">
                    Kamera memerlukan izin browser dan halaman HTTPS atau localhost. Anda juga dapat mencari tiket secara manual.
                </div>
                <label class="form-label" for="ticketLookup">Nomor tiket atau plat nomor</label>
                <input class="form-control" id="ticketLookup" type="search" autocomplete="off" placeholder="Contoh: ID-PARKIR-123 atau B 1234 ABC">
                <div class="form-text">Masukkan nomor tiket atau plat. Transaksi aktif yang cocok akan dipilih.</div>

                <div class="selected-ticket mt-3" id="selectedTicket" hidden>
                    <div class="small text-muted mb-1">Transaksi terpilih</div>
                    <div class="fw-semibold" id="selectedTicketTitle"></div>
                    <div class="small text-muted mt-1" id="selectedTicketDetails"></div>
                </div>
                <form method="post" class="mt-3" id="exitForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id_parkir" id="selectedParkingId" value="">
                    <button class="btn btn-success w-100" type="submit" id="processExitButton" disabled>
                        <i class="bi bi-check2-circle me-1"></i>Konfirmasi pembayaran & proses keluar
                    </button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-xl-7">
        <section class="card h-100">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="stat-icon green"><i class="bi bi-car-front"></i></span>
                            <h2 class="section-title">Kendaraan sedang parkir</h2>
                        </div>
                        <p class="small text-muted mb-0 mt-2"><?= count($transactions) ?> transaksi aktif</p>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead><tr><th>No. parkir</th><th>Plat nomor</th><th>Jenis</th><th>Area</th><th>Waktu masuk</th><th>Aksi</th></tr></thead>
                        <tbody id="activeTransactionsTable">
                        <?php foreach ($transactions as $transaction): ?>
                            <tr data-parking-id="<?= (int) $transaction['id_parkir'] ?>"
                                data-plate="<?= e($transaction['plat_nomor']) ?>"
                                data-type="<?= e($transaction['jenis_kendaraan']) ?>"
                                data-area="<?= e($transaction['nama_area']) ?>"
                                data-entry-time="<?= e($transaction['waktu_masuk']) ?>">
                                <td><?= (int) $transaction['id_parkir'] ?></td>
                                <td class="fw-semibold"><?= e($transaction['plat_nomor']) ?></td>
                                <td><?= e(ucfirst($transaction['jenis_kendaraan'])) ?></td>
                                <td><?= e($transaction['nama_area']) ?></td>
                                <td><?= e($transaction['waktu_masuk']) ?></td>
                                <td><button class="btn btn-sm btn-outline-primary select-ticket" type="button" data-select-id="<?= (int) $transaction['id_parkir'] ?>">Pilih</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$transactions): ?><tr id="emptyTransactions"><td colspan="6" class="table-empty">Tidak ada kendaraan yang perlu diproses.</td></tr><?php endif; ?>
                        <tr id="noMatches" hidden><td colspan="6" class="table-empty">Tidak ada transaksi aktif yang cocok.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
    (function () {
        var transactions = Array.prototype.slice.call(document.querySelectorAll('#activeTransactionsTable tr[data-parking-id]'));
        var lookup = document.getElementById('ticketLookup');
        var idInput = document.getElementById('selectedParkingId');
        var selectedBox = document.getElementById('selectedTicket');
        var selectedTitle = document.getElementById('selectedTicketTitle');
        var selectedDetails = document.getElementById('selectedTicketDetails');
        var processButton = document.getElementById('processExitButton');
        var message = document.getElementById('scannerMessage');
        var noMatches = document.getElementById('noMatches');
        var cameraButton = document.getElementById('cameraButton');
        var stopCameraButton = document.getElementById('stopCameraButton');
        var reader = null;
        var cameraRunning = false;

        function normalize(value) {
            return value.trim().toUpperCase().replace(/\s+/g, ' ');
        }

        function selectTransaction(row, updateLookup) {
            if (!row) {
                idInput.value = '';
                selectedBox.hidden = true;
                processButton.disabled = true;
                return;
            }
            var id = row.getAttribute('data-parking-id');
            var plate = row.getAttribute('data-plate');
            idInput.value = id;
            selectedTitle.textContent = 'ID-PARKIR-' + id + ' - ' + plate;
            selectedDetails.textContent = row.getAttribute('data-type') + ' | ' +
                row.getAttribute('data-area') + ' | Masuk ' + row.getAttribute('data-entry-time');
            selectedBox.hidden = false;
            processButton.disabled = false;
            transactions.forEach(function (item) {
                item.classList.toggle('table-primary', item === row);
            });
            if (updateLookup) {
                lookup.value = 'ID-PARKIR-' + id;
            }
            message.textContent = 'Tiket berhasil dipilih. Periksa transaksi lalu konfirmasi pembayaran untuk memproses kendaraan keluar.';
        }

        function findTransaction(value) {
            var normalized = normalize(value);
            var ticketMatch = normalized.match(/^ID-PARKIR-(\d+)$/);
            if (ticketMatch) {
                return transactions.find(function (row) {
                    return row.getAttribute('data-parking-id') === ticketMatch[1];
                }) || null;
            }
            if (/^\d+$/.test(normalized)) {
                return transactions.find(function (row) {
                    return row.getAttribute('data-parking-id') === normalized;
                }) || null;
            }
            var plateMatches = transactions.filter(function (row) {
                return normalize(row.getAttribute('data-plate')) === normalized;
            });
            return plateMatches.length === 1 ? plateMatches[0] : null;
        }

        function filterTransactions() {
            var query = normalize(lookup.value);
            var visible = 0;
            transactions.forEach(function (row) {
                var searchable = normalize(
                    row.getAttribute('data-parking-id') + ' ' +
                    row.getAttribute('data-plate') + ' ' +
                    row.getAttribute('data-type') + ' ' +
                    row.getAttribute('data-area')
                );
                var matches = query === '' || searchable.indexOf(query) !== -1 ||
                    ('ID-PARKIR-' + row.getAttribute('data-parking-id')).indexOf(query) !== -1;
                row.hidden = !matches;
                if (matches) {
                    visible++;
                }
            });
            noMatches.hidden = transactions.length === 0 || query === '' || visible !== 0;
        }

        function resolveLookup(value) {
            filterTransactions();
            if (!value.trim()) {
                selectTransaction(null, false);
                message.textContent = 'Kamera memerlukan izin browser dan halaman HTTPS atau localhost. Anda juga dapat mencari tiket secara manual.';
                return;
            }
            var row = findTransaction(value);
            if (row) {
                selectTransaction(row, false);
                filterTransactions();
                return;
            }
            selectTransaction(null, false);
            message.textContent = 'Tiket tidak ditemukan pada transaksi aktif. Periksa nomor tiket/plat atau pilih kendaraan dari daftar.';
        }

        async function stopCamera() {
            if (reader && cameraRunning) {
                try {
                    await reader.stop();
                } finally {
                    cameraRunning = false;
                    reader.clear();
                }
            }
            cameraButton.hidden = false;
            stopCameraButton.hidden = true;
        }

        lookup.addEventListener('input', function () {
            resolveLookup(lookup.value);
        });
        document.querySelectorAll('.select-ticket').forEach(function (button) {
            button.addEventListener('click', function () {
                var row = transactions.find(function (item) {
                    return item.getAttribute('data-parking-id') === button.getAttribute('data-select-id');
                });
                selectTransaction(row, true);
                filterTransactions();
            });
        });
        document.getElementById('exitForm').addEventListener('submit', function (event) {
            if (!idInput.value || !window.confirm('Selesaikan transaksi ini? Sistem akan menghitung biaya parkir dan memperbarui slot.')) {
                event.preventDefault();
            }
        });
        cameraButton.addEventListener('click', async function () {
            if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                message.textContent = 'Akses kamera hanya tersedia melalui HTTPS atau localhost dan browser yang mendukung kamera. Gunakan input manual di bawah.';
                return;
            }
            if (typeof Html5Qrcode === 'undefined') {
                message.textContent = 'Library pemindai gagal dimuat. Periksa koneksi internet atau gunakan input manual.';
                return;
            }
            try {
                reader = new Html5Qrcode('reader');
                var cameras = await Html5Qrcode.getCameras();
                if (!cameras || cameras.length === 0) {
                    message.textContent = 'Kamera tidak ditemukan pada perangkat ini. Gunakan input manual.';
                    return;
                }
                var preferredCamera = cameras.find(function (camera) {
                    return /back|rear|environment/i.test(camera.label);
                }) || cameras[0];
                cameraRunning = true;
                cameraButton.hidden = true;
                stopCameraButton.hidden = false;
                message.textContent = 'Kamera aktif. Arahkan ke QR Code pada struk parkir.';
                await reader.start(
                    preferredCamera.id,
                    { fps: 10, qrbox: { width: 230, height: 230 }, aspectRatio: 1 },
                    async function (decodedText) {
                        lookup.value = decodedText;
                        resolveLookup(decodedText);
                        await stopCamera();
                        lookup.focus();
                    },
                    function () {}
                );
            } catch (error) {
                cameraRunning = false;
                cameraButton.hidden = false;
                stopCameraButton.hidden = true;
                var errorName = error && error.name ? error.name : '';
                var errorText = error && error.message ? error.message : String(error || '');
                var detail;

                if (errorName === 'NotAllowedError' || errorName === 'PermissionDeniedError') {
                    detail = 'Akses kamera ditolak atau diblokir. Klik ikon di kiri alamat localhost, izinkan Kamera, lalu muat ulang halaman.';
                } else if (errorName === 'NotFoundError' || errorName === 'DevicesNotFoundError') {
                    detail = 'Browser tidak menemukan kamera. Periksa apakah webcam terpasang dan terdeteksi Windows.';
                } else if (errorName === 'NotReadableError' || errorName === 'TrackStartError') {
                    detail = 'Kamera terdeteksi tetapi tidak bisa dipakai. Tutup aplikasi lain yang menggunakan kamera, lalu coba lagi.';
                } else if (errorName === 'OverconstrainedError' || errorName === 'ConstraintNotSatisfiedError') {
                    detail = 'Kamera tidak mendukung pengaturan pemindaian. Coba pilih kamera lain atau gunakan input manual.';
                } else if (errorName === 'SecurityError' || !window.isSecureContext) {
                    detail = 'Browser memblokir akses kamera. Buka aplikasi melalui localhost atau HTTPS.';
                } else {
                    detail = 'Kamera gagal dibuka' + (errorText ? ' (' + errorText + ')' : '') + '. Periksa izin kamera dan tutup aplikasi lain yang mungkin menggunakannya.';
                }
                console.error('QR camera start failed:', error);
                message.textContent = detail + ' Jika tetap gagal, gunakan input manual.';
                if (reader) {
                    try {
                        reader.clear();
                    } catch (clearError) {
                        console.warn('QR scanner cleanup failed:', clearError);
                    }
                    reader = null;
                }
            }
        });
        stopCameraButton.addEventListener('click', function () {
            stopCamera().then(function () {
                message.textContent = 'Pemindaian kamera dihentikan.';
            }).catch(function () {
                message.textContent = 'Kamera gagal dihentikan dengan normal. Muat ulang halaman jika indikator kamera tetap aktif.';
            });
        });
    })();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
