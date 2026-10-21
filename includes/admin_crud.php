<?php
require_once __DIR__ . '/app.php';
require_role('admin');

$pdo = db();
$errors = [];
$saved = [];
$editingId = isset($_GET['edit'])
    ? (int) $_GET['edit']
    : (($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') ? (int) ($_POST['id'] ?? 0) : 0);
$editing = null;

if ($editingId > 0) {
    $editColumns = array_values(array_diff(array_keys($crud['fields']), ['password']));
    $editColumns[] = $crud['pk'];
    $statement = $pdo->prepare('SELECT ' . implode(', ', array_unique($editColumns)) . ' FROM ' . $crud['table'] . ' WHERE ' . $crud['pk'] . ' = :id');
    $statement->execute(['id' => $editingId]);
    $editing = $statement->fetch();
    if (!$editing) {
        http_response_code(404);
        exit('Data yang diminta tidak ditemukan.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $recordId = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete' && $recordId > 0) {
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('DELETE FROM ' . $crud['table'] . ' WHERE ' . $crud['pk'] . ' = :id');
            $statement->execute(['id' => $recordId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Data tidak ditemukan atau sudah dihapus.');
            }
            log_activity($pdo, (int) $_SESSION['id_user'], 'Hapus ' . $crud['title'] . ' #' . $recordId);
            $pdo->commit();
            set_flash('success', 'Data berhasil dihapus.');
            redirect($crud['route']);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Gagal menghapus ' . $crud['table'] . ': ' . $exception->getMessage());
            $errors[] = 'Data tidak dapat dihapus. Pastikan data tidak sedang digunakan oleh transaksi atau data lain.';
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage();
        }
    } elseif (in_array($action, ['create', 'update'], true)) {
        $isUpdate = $action === 'update';
        $recordId = $isUpdate ? $recordId : 0;
        $data = [];

        if ($isUpdate && $recordId < 1) {
            $errors[] = 'ID data tidak valid.';
        }

        foreach ($crud['fields'] as $name => $field) {
            $value = trim((string) ($_POST[$name] ?? ''));
            if (!empty($field['lowercase'])) {
                $value = strtolower($value);
            }
            if ($field['type'] === 'password' && $isUpdate && $value === '') {
                continue;
            }
            if (!empty($field['required']) && $value === '') {
                $errors[] = $field['label'] . ' wajib diisi.';
                continue;
            }
            if ($value === '' && empty($field['required'])) {
                continue;
            }
            if ($field['type'] === 'select' && !array_key_exists($value, $field['options'])) {
                $errors[] = 'Pilihan ' . $field['label'] . ' tidak valid.';
                continue;
            }
            if ($field['type'] === 'number' && (!ctype_digit($value) || (int) $value < (int) ($field['min'] ?? 0))) {
                $errors[] = $field['label'] . ' harus berupa bilangan bulat yang valid.';
                continue;
            }
            if (isset($field['max']) && strlen($value) > $field['max']) {
                $errors[] = $field['label'] . ' terlalu panjang.';
                continue;
            }
            if ($field['type'] === 'password') {
                $data[$name] = password_hash($value, PASSWORD_DEFAULT);
            } else {
                $data[$name] = $value;
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($isUpdate) {
                    $set = [];
                    foreach (array_keys($data) as $name) {
                        $set[] = $name . ' = :' . $name;
                    }
                    if (!$set) {
                        throw new RuntimeException('Tidak ada data yang diperbarui.');
                    }
                    $data['record_id'] = $recordId;
                    $sql = 'UPDATE ' . $crud['table'] . ' SET ' . implode(', ', $set) . ' WHERE ' . $crud['pk'] . ' = :record_id';
                    $statement = $pdo->prepare($sql);
                    $statement->execute($data);
                    $activity = 'Edit ' . $crud['title'] . ' #' . $recordId;
                } else {
                    $names = array_keys($data);
                    $placeholders = array_map(function ($name) {
                        return ':' . $name;
                    }, $names);
                    $sql = 'INSERT INTO ' . $crud['table'] . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', $placeholders) . ')';
                    $statement = $pdo->prepare($sql);
                    $statement->execute($data);
                    $recordId = (int) $pdo->lastInsertId();
                    $activity = 'Tambah ' . $crud['title'] . ' #' . $recordId;
                }
                log_activity($pdo, (int) $_SESSION['id_user'], $activity);
                $pdo->commit();
                set_flash('success', 'Data berhasil disimpan.');
                redirect($crud['route']);
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Gagal menyimpan ' . $crud['table'] . ': ' . $exception->getMessage());
                $errors[] = 'Data gagal disimpan. Periksa nilai yang dimasukkan dan pastikan data unik.';
            } catch (RuntimeException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $exception->getMessage();
            }
        }
        $saved = $_POST;
    } else {
        $errors[] = 'Aksi yang diminta tidak valid.';
    }
}

$listColumns = array_values(array_unique(array_merge([$crud['pk']], array_keys($crud['columns']))));
$perPage = 20;
$totalRecords = (int) $pdo->query('SELECT COUNT(*) FROM ' . $crud['table'])->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $perPage));
$currentPage = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));
$offset = ($currentPage - 1) * $perPage;
$statement = $pdo->query('SELECT ' . implode(', ', $listColumns) . ' FROM ' . $crud['table'] . ' ORDER BY ' . $crud['pk'] . ' DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$records = $statement->fetchAll();
$pageTitle = $crud['title'];
require __DIR__ . '/header.php';
?>
<div class="page-heading d-flex justify-content-between align-items-center">
    <div><h1><?= e($crud['title']) ?></h1><p>Kelola dan pantau <?= e(strtolower($crud['title'])) ?> dalam satu tempat.</p></div>
    <?php if ($editing): ?><a class="btn btn-outline-secondary" href="<?= e(app_url($crud['route'])) ?>"><i class="bi bi-x-lg me-1"></i>Batal edit</a><?php endif; ?>
</div>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<section class="card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-3"><span class="stat-icon green"><i class="bi <?= $editing ? 'bi-pencil-square' : 'bi-plus-lg' ?>"></i></span><h2 class="section-title"><?= $editing ? 'Ubah data' : 'Tambah data' ?></h2></div>
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing[$crud['pk']] ?>"><?php endif; ?>
            <?php foreach ($crud['fields'] as $name => $field):
                $current = array_key_exists($name, $saved) ? $saved[$name] : ($editing[$name] ?? '');
                ?>
                <div class="col-md-6">
                    <label class="form-label" for="<?= e($name) ?>"><?= e($field['label']) ?></label>
                    <?php if ($field['type'] === 'select'): ?>
                        <select class="form-select" id="<?= e($name) ?>" name="<?= e($name) ?>" <?= !empty($field['required']) ? 'required' : '' ?>>
                            <option value="">Pilih <?= e(strtolower($field['label'])) ?></option>
                            <?php foreach ($field['options'] as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= (string) $current === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input class="form-control" id="<?= e($name) ?>" name="<?= e($name) ?>"
                               type="<?= e($field['type'] === 'number' ? 'number' : ($field['type'] === 'password' ? 'password' : 'text')) ?>"
                               <?= !empty($field['suggestions']) ? 'list="' . e($name) . 'Suggestions"' : '' ?>
                               <?= isset($field['max']) ? 'maxlength="' . (int) $field['max'] . '"' : '' ?>
                               <?= isset($field['min']) ? 'min="' . (int) $field['min'] . '"' : '' ?>
                               <?= $field['type'] === 'password' ? 'autocomplete="new-password"' : '' ?>
                               value="<?= $field['type'] === 'password' ? '' : e($current) ?>"
                               <?= !empty($field['required']) && (!$editing || $field['type'] !== 'password') ? 'required' : '' ?>>
                        <?php if (!empty($field['suggestions'])): ?>
                            <datalist id="<?= e($name) ?>Suggestions">
                                <?php foreach ($field['suggestions'] as $value => $label): ?>
                                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                        <?php endif; ?>
                        <?php if ($field['type'] === 'password' && $editing): ?><div class="form-text">Kosongkan jika kata sandi tidak diubah.</div><?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Simpan data</button></div>
        </form>
    </div>
</section>
<section class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="section-title">Daftar <?= e(strtolower($crud['title'])) ?></h2>
            <span class="badge text-bg-light"><?= $totalRecords ?> data</span>
        </div>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead><tr><?php foreach ($crud['columns'] as $label): ?><th><?= e($label) ?></th><?php endforeach; ?><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <?php foreach ($crud['columns'] as $column => $label): ?>
                            <td>
                                <?php if ($column === 'status_aktif'): ?>
                                    <span class="badge <?= (int) $record[$column] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $record[$column] === 1 ? 'Aktif' : 'Nonaktif' ?></span>
                                <?php else: ?>
                                    <?= e($record[$column]) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url($crud['route'] . '?edit=' . (int) $record[$crud['pk']])) ?>" aria-label="Edit data"><i class="bi bi-pencil"></i></a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus data ini?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $record[$crud['pk']] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Hapus data"><i class="bi bi-trash3"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$records): ?><tr><td colspan="<?= count($crud['columns']) + 1 ?>" class="text-center text-muted">Belum ada data.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="mt-3" aria-label="Pagination data">
                <ul class="pagination pagination-sm justify-content-end mb-0">
                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(app_url($crud['route'] . '?page=' . max(1, $currentPage - 1))) ?>" aria-label="Halaman sebelumnya"><i class="bi bi-chevron-left"></i></a></li>
                    <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);
                    if ($startPage > 1):
                    ?>
                        <li class="page-item"><a class="page-link" href="<?= e(app_url($crud['route'] . '?page=1')) ?>">1</a></li>
                        <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                        <li class="page-item <?= $page === $currentPage ? 'active' : '' ?>"><a class="page-link" href="<?= e(app_url($crud['route'] . '?page=' . $page)) ?>"><?= $page ?></a></li>
                    <?php endfor; ?>
                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= e(app_url($crud['route'] . '?page=' . $totalPages)) ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(app_url($crud['route'] . '?page=' . min($totalPages, $currentPage + 1))) ?>" aria-label="Halaman selanjutnya"><i class="bi bi-chevron-right"></i></a></li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
