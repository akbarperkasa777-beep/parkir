<?php
require_once __DIR__ . '/../includes/app.php';
require_role('petugas');
$parkingId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$parkingId || $parkingId < 1) {
    http_response_code(400);
    exit('Nomor transaksi tidak valid.');
}
$statement = db()->prepare('SELECT t.id_parkir, t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total, t.status, k.plat_nomor, k.jenis_kendaraan, k.warna, k.pemilik, a.nama_area, tr.tarif_per_jam FROM tb_transaksi t JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan JOIN tb_area_parkir a ON a.id_area = t.id_area JOIN tb_tarif tr ON tr.id_tarif = t.id_tarif WHERE t.id_parkir = :id');
$statement->execute(['id' => $parkingId]);
$receipt = $statement->fetch();
if (!$receipt) {
    http_response_code(404);
    exit('Transaksi tidak ditemukan.');
}
$qrValue = 'ID-PARKIR-' . (int) $receipt['id_parkir'];
$pageTitle = 'Struk Parkir #' . $parkingId;
require __DIR__ . '/../includes/header.php';
?>
<div class="receipt">
    <?php if ($receipt['status'] === 'keluar'): ?>
        <div class="alert alert-success no-print" role="status"><i class="bi bi-check-circle-fill me-1"></i>Pembayaran berhasil dikonfirmasi. Transaksi parkir telah selesai.</div>
    <?php endif; ?>
    <div class="card"><div class="card-body p-4">
        <div class="receipt-title text-center">
            <span class="stat-icon green mx-auto mb-3"><i class="bi bi-p-square-fill"></i></span>
            <h1 class="h4 mb-1">STRUK PARKIR</h1><div class="text-muted small">Nomor #<?= (int) $receipt['id_parkir'] ?></div>
        </div>
        <dl class="row mb-3">
            <dt class="col-5">Plat nomor</dt><dd class="col-7"><?= e($receipt['plat_nomor']) ?></dd>
            <dt class="col-5">Kendaraan</dt><dd class="col-7"><?= e(ucfirst($receipt['jenis_kendaraan']) . ' - ' . $receipt['warna']) ?></dd>
            <dt class="col-5">Pemilik</dt><dd class="col-7"><?= e($receipt['pemilik']) ?></dd>
            <dt class="col-5">Area</dt><dd class="col-7"><?= e($receipt['nama_area']) ?></dd>
            <dt class="col-5">Waktu masuk</dt><dd class="col-7"><?= e($receipt['waktu_masuk']) ?></dd>
            <?php if ($receipt['status'] === 'keluar'): ?>
                <dt class="col-5">Waktu keluar</dt><dd class="col-7"><?= e($receipt['waktu_keluar']) ?></dd>
                <dt class="col-5">Durasi</dt><dd class="col-7"><?= (int) $receipt['durasi_jam'] ?> jam</dd>
                <dt class="col-5">Tarif per jam</dt><dd class="col-7"><?= e(format_rupiah($receipt['tarif_per_jam'])) ?></dd>
                <dt class="col-5 fw-bold receipt-total">Total bayar</dt><dd class="col-7 fw-bold receipt-total"><?= e(format_rupiah($receipt['biaya_total'])) ?></dd>
            <?php else: ?>
                <dt class="col-5">Status</dt><dd class="col-7"><span class="badge text-bg-success">Sedang parkir</span></dd>
            <?php endif; ?>
        </dl>
        <div class="receipt-qr text-center" data-qr-value="<?= e($qrValue) ?>">
            <div class="receipt-qr-code mx-auto" id="receiptQrCode" role="img" aria-label="QR Code tiket <?= e($qrValue) ?>"></div>
            <div class="receipt-qr-ticket mt-2 fw-semibold"><?= e($qrValue) ?></div>
            <p class="small text-muted mt-1 mb-3">Pindai QR Code ini saat memproses kendaraan keluar.</p>
        </div>
        <p class="text-center text-muted small mb-0">Terima kasih. Simpan struk ini sebagai bukti parkir.</p>
    </div></div>
    <div class="d-flex justify-content-center gap-2 mt-3 no-print">
        <button class="btn btn-primary" id="printReceipt" type="button" disabled><i class="bi bi-printer me-1"></i>Cetak struk</button>
        <a class="btn btn-outline-secondary" href="<?= e(app_url($receipt['status'] === 'masuk' ? 'petugas/transaksi_masuk.php' : 'petugas/transaksi_keluar.php')) ?>">Kembali</a>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var printButton = document.getElementById('printReceipt');
        var qrContainer = document.getElementById('receiptQrCode');
        var qrValue = qrContainer.parentElement.getAttribute('data-qr-value');

        printButton.addEventListener('click', function () {
            window.print();
        });

        if (typeof QRCode === 'undefined') {
            qrContainer.textContent = 'QR Code tidak dapat dimuat. Periksa koneksi internet lalu muat ulang halaman.';
            qrContainer.classList.add('receipt-qr-error');
            return;
        }

        new QRCode(qrContainer, {
            text: qrValue,
            width: 176,
            height: 176,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
        printButton.disabled = false;
    });
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
