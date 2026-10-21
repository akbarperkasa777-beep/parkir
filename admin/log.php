<?php
require_once __DIR__ . '/../includes/app.php';
require_role('admin');
$pdo = db();
$logs = $pdo->query('SELECT l.id_log, u.nama_lengkap, l.aktivitas, l.waktu_aktivitas FROM tb_log_aktivitas l JOIN tb_user u ON u.id_user = l.id_user ORDER BY l.id_log DESC LIMIT 500')->fetchAll();
$pageTitle = 'Log Aktivitas';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading"><h1>Log Aktivitas</h1><p>Riwayat aktivitas pengguna dalam sistem.</p></div>
<div class="card"><div class="card-body">
    <div class="table-responsive">
    <table class="table table-striped mb-0">
        <thead><tr><th>ID</th><th>User</th><th>Aktivitas</th><th>Waktu</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr><td><?= (int) $log['id_log'] ?></td><td><?= e($log['nama_lengkap']) ?></td><td><?= e($log['aktivitas']) ?></td><td><?= e($log['waktu_aktivitas']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="4" class="text-center text-muted">Belum ada aktivitas.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
