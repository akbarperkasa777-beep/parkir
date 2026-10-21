<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$pdo = db();
$backupDirectory = getenv('APP_BACKUP_DIR') ?: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'parkir-app-backups';
$maximumUploadBytes = 50 * 1024 * 1024;
$errors = [];
$allowedBackupName = '/^(?:parkir_backup|parkir_pre_restore)_\d{8}_\d{6}_[a-f0-9]{8}\.sql$/';

function backup_quote_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_$]+$/D', $identifier)) {
        throw new RuntimeException('Nama tabel tidak didukung oleh modul backup.');
    }
    return '`' . $identifier . '`';
}

function backup_ensure_directory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder backup tidak dapat dibuat. Periksa izin tulis folder storage.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('Folder backup tidak dapat ditulis. Periksa izin folder storage/backups.');
    }
}

function backup_write_line($stream, string $line): void
{
    if (fwrite($stream, $line . PHP_EOL) === false) {
        throw new RuntimeException('Gagal menulis file backup ke server.');
    }
}

function backup_sql_literal(PDO $pdo, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    $value = (string) $value;
    if (preg_match('//u', $value) && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
        $quoted = $pdo->quote($value, PDO::PARAM_STR);
        if ($quoted !== false) {
            return $quoted;
        }
    }
    return '0x' . bin2hex($value);
}

function backup_create_dump(PDO $pdo, string $filePath): void
{
    $stream = fopen($filePath, 'wb');
    if ($stream === false) {
        throw new RuntimeException('File backup tidak dapat dibuat.');
    }

    try {
        backup_write_line($stream, '-- PARKIR-APP DATABASE BACKUP v1');
        backup_write_line($stream, '-- Dibuat: ' . date('Y-m-d H:i:s'));
        backup_write_line($stream, 'SET NAMES utf8mb4;');
        backup_write_line($stream, 'SET FOREIGN_KEY_CHECKS=0;');

        $objects = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $tables = [];
        $views = [];
        foreach ($objects as $object) {
            $name = (string) ($object[0] ?? '');
            $type = strtoupper((string) ($object[1] ?? ''));
            backup_quote_identifier($name);
            if ($type === 'BASE TABLE') {
                $tables[] = $name;
            } elseif ($type === 'VIEW') {
                $views[] = $name;
            }
        }

        foreach ($views as $view) {
            backup_write_line($stream, 'DROP VIEW IF EXISTS ' . backup_quote_identifier($view) . ';');
        }

        foreach ($tables as $table) {
            $quotedTable = backup_quote_identifier($table);
            $createRow = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_ASSOC);
            $createSql = (string) ($createRow['Create Table'] ?? '');
            if ($createSql === '') {
                throw new RuntimeException('Struktur tabel ' . $table . ' tidak dapat dibaca.');
            }

            backup_write_line($stream, 'DROP TABLE IF EXISTS ' . $quotedTable . ';');
            backup_write_line($stream, $createSql . ';');

            $rows = $pdo->query('SELECT * FROM ' . $quotedTable);
            $columnNames = [];
            for ($index = 0; $index < $rows->columnCount(); $index++) {
                $column = $rows->getColumnMeta($index);
                $columnName = (string) ($column['name'] ?? '');
                $columnNames[] = backup_quote_identifier($columnName);
            }
            $columnSql = implode(', ', $columnNames);

            while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                $values = [];
                foreach ($row as $value) {
                    $values[] = backup_sql_literal($pdo, $value);
                }
                backup_write_line(
                    $stream,
                    'INSERT INTO ' . $quotedTable . ' (' . $columnSql . ') VALUES (' . implode(', ', $values) . ');'
                );
            }
        }

        foreach ($views as $view) {
            $quotedView = backup_quote_identifier($view);
            $viewRow = $pdo->query('SHOW CREATE VIEW ' . $quotedView)->fetch(PDO::FETCH_ASSOC);
            $createView = (string) ($viewRow['Create View'] ?? '');
            $viewPosition = stripos($createView, ' VIEW ');
            $asPosition = $viewPosition === false ? false : stripos($createView, ' AS ', $viewPosition + 6);
            if ($asPosition === false) {
                throw new RuntimeException('Definisi view ' . $view . ' tidak dapat dibaca.');
            }
            $selectSql = trim(substr($createView, $asPosition + 4));
            backup_write_line($stream, 'CREATE OR REPLACE VIEW ' . $quotedView . ' AS ' . $selectSql . ';');
        }

        backup_write_line($stream, 'SET FOREIGN_KEY_CHECKS=1;');
        if (!fflush($stream)) {
            throw new RuntimeException('File backup tidak dapat diselesaikan.');
        }
    } catch (Throwable $exception) {
        fclose($stream);
        @unlink($filePath);
        throw $exception;
    }

    fclose($stream);
}

function backup_split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = '';
    $length = strlen($sql);
    $lineComment = false;
    $blockComment = false;

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($lineComment) {
            if ($character === "\n") {
                $lineComment = false;
                $buffer .= "\n";
            }
            continue;
        }
        if ($blockComment) {
            if ($character === '*' && $next === '/') {
                $blockComment = false;
                $index++;
            }
            continue;
        }
        if ($quote !== '') {
            $buffer .= $character;
            if ($character === '\\' && $quote !== '`' && $next !== '') {
                $buffer .= $next;
                $index++;
            } elseif ($character === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $index++;
                } else {
                    $quote = '';
                }
            }
            continue;
        }

        if ($character === "'" || $character === '"' || $character === '`') {
            $quote = $character;
            $buffer .= $character;
        } elseif ($character === '#' || ($character === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2])))) {
            $lineComment = true;
            if ($character === '-') {
                $index++;
            }
        } elseif ($character === '/' && $next === '*') {
            $blockComment = true;
            $index++;
        } elseif ($character === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        } else {
            $buffer .= $character;
        }
    }

    if ($quote !== '' || $blockComment) {
        throw new RuntimeException('File SQL memiliki kutip atau komentar yang tidak lengkap.');
    }
    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }
    return $statements;
}

function backup_split_top_level(string $text, string $separator = ','): array
{
    $parts = [];
    $buffer = '';
    $quote = '';
    $depth = 0;
    $length = strlen($text);

    for ($index = 0; $index < $length; $index++) {
        $character = $text[$index];
        $next = $index + 1 < $length ? $text[$index + 1] : '';
        if ($quote !== '') {
            $buffer .= $character;
            if ($character === '\\' && $quote !== '`' && $next !== '') {
                $buffer .= $next;
                $index++;
            } elseif ($character === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $index++;
                } else {
                    $quote = '';
                }
            }
            continue;
        }
        if ($character === "'" || $character === '"' || $character === '`') {
            $quote = $character;
            $buffer .= $character;
        } elseif ($character === '(') {
            $depth++;
            $buffer .= $character;
        } elseif ($character === ')') {
            $depth--;
            if ($depth < 0) {
                throw new RuntimeException('Format nilai pada file SQL tidak valid.');
            }
            $buffer .= $character;
        } elseif ($character === $separator && $depth === 0) {
            $parts[] = trim($buffer);
            $buffer = '';
        } else {
            $buffer .= $character;
        }
    }

    if ($quote !== '' || $depth !== 0) {
        throw new RuntimeException('Format nilai pada file SQL tidak lengkap.');
    }
    if (trim($buffer) !== '' || $parts) {
        $parts[] = trim($buffer);
    }
    return $parts;
}

function backup_validate_restore(string $sql): array
{
    if (strpos($sql, '-- PARKIR-APP DATABASE BACKUP v1') === false) {
        throw new RuntimeException('File bukan backup PARKIR-APP yang dikenali. Restore hanya menerima file backup yang dibuat oleh aplikasi ini.');
    }
    $statements = backup_split_sql_statements($sql);
    if (!$statements || count($statements) > 200000) {
        throw new RuntimeException('File SQL kosong atau berisi terlalu banyak perintah.');
    }
    $dumpTables = [];
    $dumpViews = [];
    foreach ($statements as $statement) {
        if (preg_match('/^CREATE\s+TABLE\s+`([A-Za-z0-9_$]+)`\s+\([\s\S]*\)\s+ENGINE\s*=/iD', $statement, $match)) {
            $dumpTables[$match[1]] = true;
        } elseif (preg_match('/^CREATE\s+OR\s+REPLACE\s+VIEW\s+`([A-Za-z0-9_$]+)`\s+AS\s+SELECT\b[\s\S]+$/iD', $statement, $match)) {
            $dumpViews[$match[1]] = true;
        }
    }

    $createdTables = [];
    foreach ($statements as $statement) {
        if (preg_match('/^SET\s+(?:NAMES\s+utf8mb4|FOREIGN_KEY_CHECKS\s*=\s*[01])$/iD', $statement)) {
            continue;
        }
        if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`([A-Za-z0-9_$]+)`$/iD', $statement, $match)) {
            if (!isset($dumpTables[$match[1]])) {
                throw new RuntimeException('File backup berusaha menghapus tabel yang tidak disertakan dalam backup.');
            }
            continue;
        }
        if (preg_match('/^DROP\s+VIEW\s+IF\s+EXISTS\s+`([A-Za-z0-9_$]+)`$/iD', $statement, $match)) {
            if (!isset($dumpViews[$match[1]])) {
                throw new RuntimeException('File backup berusaha menghapus view yang tidak disertakan dalam backup.');
            }
            continue;
        }
        if (preg_match('/^CREATE\s+TABLE\s+`([A-Za-z0-9_$]+)`\s+\([\s\S]*\)\s+ENGINE\s*=/iD', $statement, $match)) {
            $createdTables[$match[1]] = true;
            continue;
        }
        if (preg_match('/^INSERT\s+INTO\s+`([A-Za-z0-9_$]+)`\s+\((.+)\)\s+VALUES\s+(.+)$/isD', $statement, $match)) {
            $table = $match[1];
            if (!isset($createdTables[$table])) {
                throw new RuntimeException('Data untuk tabel ' . $table . ' muncul sebelum struktur tabelnya.');
            }
            $columns = backup_split_top_level($match[2]);
            if (!$columns) {
                throw new RuntimeException('Daftar kolom pada file backup tidak valid.');
            }
            foreach ($columns as $column) {
                if (!preg_match('/^`[A-Za-z0-9_$]+`$/D', $column)) {
                    throw new RuntimeException('File backup memuat nama kolom yang tidak valid.');
                }
            }
            foreach (backup_split_top_level($match[3]) as $row) {
                if (strlen($row) < 2 || $row[0] !== '(' || substr($row, -1) !== ')') {
                    throw new RuntimeException('Format baris data pada file backup tidak valid.');
                }
                $values = backup_split_top_level(substr($row, 1, -1));
                if (count($values) !== count($columns)) {
                    throw new RuntimeException('Jumlah nilai data tidak sesuai dengan kolom tabel.');
                }
                foreach ($values as $value) {
                    if (!preg_match('/^(?:NULL|TRUE|FALSE|[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?|\'(?:\\\\.|\'\'|[^\'\\\\])*\'|"(?:\\\\.|""|[^"\\\\])*"|0x[0-9a-f]+|X\'[0-9a-f]*\')$/isD', $value)) {
                        throw new RuntimeException('File backup memuat nilai SQL yang tidak aman untuk dipulihkan.');
                    }
                }
            }
            continue;
        }
        if (preg_match('/^CREATE\s+OR\s+REPLACE\s+VIEW\s+`([A-Za-z0-9_$]+)`\s+AS\s+SELECT\b[\s\S]+$/iD', $statement, $match)) {
            if (!isset($dumpViews[$match[1]])) {
                throw new RuntimeException('Definisi view pada file backup tidak valid.');
            }
            continue;
        }

        throw new RuntimeException('File backup berisi perintah yang tidak didukung. Gunakan file .sql yang dibuat dari halaman ini.');
    }

    if (!$createdTables) {
        throw new RuntimeException('Tidak ditemukan struktur tabel yang dapat dipulihkan.');
    }
    return $statements;
}

function backup_apply_restore(PDO $pdo, array $statements): void
{
    foreach ($statements as $statement) {
        if (preg_match('/^INSERT\s+INTO\s+`([A-Za-z0-9_$]+)`\s+\((.+)\)\s+VALUES\s+(.+)$/isD', $statement, $match)) {
            $table = backup_quote_identifier($match[1]);
            $columns = backup_split_top_level($match[2]);
            $columnSql = implode(', ', array_map(static function (string $column): string {
                return backup_quote_identifier(substr($column, 1, -1));
            }, $columns));
            foreach (backup_split_top_level($match[3]) as $row) {
                $pdo->exec('INSERT INTO ' . $table . ' (' . $columnSql . ') VALUES ' . $row);
            }
        } else {
            $pdo->exec($statement);
        }
    }
}

function backup_format_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return $bytes . ' B';
}

if (isset($_GET['download'])) {
    $filename = (string) $_GET['download'];
    if (!preg_match($allowedBackupName, $filename)) {
        http_response_code(400);
        exit('Nama file backup tidak valid.');
    }
    $filePath = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($filePath) || !is_readable($filePath)) {
        http_response_code(404);
        exit('File backup tidak ditemukan.');
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($filePath));
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        backup_ensure_directory($backupDirectory);
        if ($action === 'save_backup' || $action === 'download_backup') {
            $prefix = $action === 'save_backup' ? 'parkir_backup' : 'parkir_backup';
            $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
            $filePath = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
            backup_create_dump($pdo, $filePath);

            if ($action === 'save_backup') {
                log_activity($pdo, (int) $_SESSION['id_user'], 'Menyimpan backup database: ' . $filename);
                set_flash('success', 'Backup database berhasil disimpan di server (' . $filename . ').');
                redirect('admin/backup_restore.php');
            }

            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string) filesize($filePath));
            header('X-Content-Type-Options: nosniff');
            readfile($filePath);
            @unlink($filePath);
            exit;
        }

        if ($action === 'delete_backup') {
            $filename = (string) ($_POST['filename'] ?? '');
            if (!preg_match($allowedBackupName, $filename)) {
                throw new RuntimeException('Nama file backup tidak valid.');
            }
            $filePath = $backupDirectory . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($filePath) || !unlink($filePath)) {
                throw new RuntimeException('File backup tidak ditemukan atau gagal dihapus.');
            }
            log_activity($pdo, (int) $_SESSION['id_user'], 'Menghapus file backup database: ' . $filename);
            set_flash('success', 'File backup berhasil dihapus.');
            redirect('admin/backup_restore.php');
        }

        if ($action === 'restore') {
            if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
                throw new RuntimeException('Pilih file backup .sql terlebih dahulu.');
            }
            $upload = $_FILES['backup_file'];
            if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'Ukuran file melebihi batas upload PHP.',
                    UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas yang diizinkan.',
                    UPLOAD_ERR_PARTIAL => 'File hanya terunggah sebagian.',
                    UPLOAD_ERR_NO_FILE => 'Pilih file backup .sql terlebih dahulu.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara upload tidak tersedia.',
                    UPLOAD_ERR_CANT_WRITE => 'File upload gagal ditulis ke server.',
                    UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh konfigurasi server.',
                ];
                throw new RuntimeException($uploadErrors[(int) $upload['error']] ?? 'Upload file gagal.');
            }
            if ((int) ($upload['size'] ?? 0) < 1 || (int) $upload['size'] > $maximumUploadBytes) {
                throw new RuntimeException('Ukuran file harus lebih dari 0 dan tidak melebihi 50 MB.');
            }
            $originalName = (string) ($upload['name'] ?? '');
            if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'sql' || !is_uploaded_file((string) $upload['tmp_name'])) {
                throw new RuntimeException('File restore harus berupa file .sql yang diunggah melalui formulir.');
            }
            $sql = file_get_contents((string) $upload['tmp_name']);
            if ($sql === false || !preg_match('//u', $sql)) {
                throw new RuntimeException('File SQL tidak dapat dibaca atau encoding-nya bukan UTF-8.');
            }
            $statements = backup_validate_restore($sql);

            $safetyFilename = 'parkir_pre_restore_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.sql';
            $safetyPath = $backupDirectory . DIRECTORY_SEPARATOR . $safetyFilename;
            backup_create_dump($pdo, $safetyPath);
            try {
                backup_apply_restore($pdo, $statements);
            } catch (Throwable $restoreException) {
                error_log('Restore database gagal: ' . $restoreException->getMessage());
                throw new RuntimeException(
                    'Restore tidak selesai. Snapshot sebelum restore tersimpan sebagai ' . $safetyFilename . '. Periksa hak akses database dan pulihkan snapshot tersebut bila diperlukan.'
                );
            }

            try {
                log_activity($pdo, (int) $_SESSION['id_user'], 'Memulihkan database dari file: ' . basename($originalName));
            } catch (Throwable $logException) {
                error_log('Restore berhasil, tetapi pencatatan log gagal: ' . $logException->getMessage());
            }
            set_flash('success', 'Restore database berhasil. Snapshot pengaman sebelum restore tersimpan sebagai ' . $safetyFilename . '.');
            redirect('admin/backup_restore.php');
        }

        throw new RuntimeException('Aksi tidak dikenal.');
    } catch (Throwable $exception) {
        error_log('Operasi backup/restore: ' . $exception->getMessage());
        $errors[] = $exception->getMessage();
    }
}

$backupFiles = [];
try {
    backup_ensure_directory($backupDirectory);
    foreach (new DirectoryIterator($backupDirectory) as $fileInfo) {
        if (!$fileInfo->isFile() || !preg_match($allowedBackupName, $fileInfo->getFilename())) {
            continue;
        }
        $backupFiles[] = [
            'name' => $fileInfo->getFilename(),
            'size' => $fileInfo->getSize(),
            'created' => $fileInfo->getMTime(),
        ];
    }
    usort($backupFiles, static function (array $left, array $right): int {
        return $right['created'] <=> $left['created'];
    });
} catch (Throwable $exception) {
    error_log('Gagal membaca direktori backup: ' . $exception->getMessage());
    $errors[] = 'Riwayat backup belum dapat dimuat. ' . $exception->getMessage();
}

$pageTitle = 'Backup & Restore Database';
require __DIR__ . '/../includes/header.php';
?>

<style>
    .backup-page { --backup-navy: #172033; --backup-red: #e50914; }
    .backup-card { height: 100%; border: 1px solid #e8edf4; border-radius: 22px; box-shadow: 0 12px 32px rgba(23,32,51,.07); }
    .backup-icon { width: 52px; height: 52px; display: inline-grid; place-items: center; border-radius: 16px; font-size: 1.35rem; }
    .backup-icon-red { color: var(--backup-red); background: #fff0f1; }
    .backup-icon-green { color: #059669; background: #e8fbf3; }
    .backup-action { border-radius: 12px; font-weight: 700; padding: .75rem 1rem; }
    .backup-table-card { border: 1px solid #e8edf4; border-radius: 22px; overflow: hidden; box-shadow: 0 12px 32px rgba(23,32,51,.06); }
    .backup-table-card .table { vertical-align: middle; }
    .backup-table-card .table thead th { color: #64748b; background: #f8fafc; font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; border-bottom: 1px solid #e8edf4; padding: .9rem 1rem; }
    .backup-table-card .table tbody td { padding: .9rem 1rem; }
    .backup-warning { border: 1px solid #fed7aa; border-radius: 14px; color: #9a3412; background: #fff7ed; }
    .backup-file-input { min-height: 48px; border-radius: 12px; }
    @media (max-width: 575.98px) { .backup-card .card-body { padding: 1.25rem !important; } .backup-table-card .table { min-width: 640px; } }
</style>

<div class="backup-page">
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endforeach; ?>

    <div class="page-heading d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div>
            <h1>Backup &amp; Restore Database</h1>
            <p>Amankan salinan database dan pulihkan data saat diperlukan.</p>
        </div>
        <span class="badge rounded-pill text-bg-light border px-3 py-2">
            <i class="bi bi-shield-lock-fill text-success me-1"></i> Admin saja
        </span>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-6">
            <section class="card backup-card">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex align-items-start gap-3 mb-4">
                        <span class="backup-icon backup-icon-red"><i class="bi bi-database-fill-down"></i></span>
                        <div>
                            <h2 class="h5 fw-bold mb-1">Backup Database</h2>
                            <p class="text-muted mb-0">Ekspor struktur tabel, seluruh data, dan definisi view database aktif.</p>
                        </div>
                    </div>
                    <div class="d-grid gap-2">
                        <form method="post" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="download_backup">
                            <button class="btn btn-danger backup-action w-100" type="submit">
                                <i class="bi bi-download me-2"></i>Generate &amp; Download Backup (.sql)
                            </button>
                        </form>
                        <form method="post" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_backup">
                            <button class="btn btn-outline-dark backup-action w-100" type="submit">
                                <i class="bi bi-cloud-arrow-down me-2"></i>Simpan Backup ke Server
                            </button>
                        </form>
                    </div>
                    <div class="small text-muted mt-3">
                        <i class="bi bi-info-circle me-1"></i>File server disimpan di lokasi privat dan tidak dapat diakses langsung melalui URL.
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-6">
            <section class="card backup-card">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex align-items-start gap-3 mb-4">
                        <span class="backup-icon backup-icon-green"><i class="bi bi-database-fill-up"></i></span>
                        <div>
                            <h2 class="h5 fw-bold mb-1">Restore Database</h2>
                            <p class="text-muted mb-0">Pulihkan database menggunakan file backup .sql dari aplikasi ini.</p>
                        </div>
                    </div>
                    <div class="backup-warning p-3 small mb-3">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Restore mengganti struktur dan data tabel yang ada. Snapshot pengaman dibuat otomatis sebelum proses.
                    </div>
                    <form method="post" enctype="multipart/form-data" id="restoreForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="restore">
                        <label for="backupFile" class="form-label fw-semibold">Pilih file backup (.sql)</label>
                        <input class="form-control backup-file-input mb-3" type="file" id="backupFile" name="backup_file" accept=".sql,text/plain,application/sql" required>
                        <div class="small text-muted mb-3">Ukuran maksimum 50 MB. Restore hanya menerima file backup resmi PARKIR-APP.</div>
                        <button class="btn btn-dark backup-action w-100" type="submit">
                            <i class="bi bi-arrow-counterclockwise me-2"></i>Jalankan Restore Database
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </div>

    <section class="backup-table-card bg-white">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-4 border-bottom">
            <div>
                <h2 class="h5 fw-bold mb-1">Riwayat File Backup di Server</h2>
                <p class="text-muted small mb-0">Snapshot manual dan snapshot pengaman sebelum restore.</p>
            </div>
            <span class="badge rounded-pill text-bg-light border"><?= count($backupFiles) ?> file</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Nama File</th><th>Ukuran File</th><th>Tanggal Pembuatan</th><th class="text-end">Aksi</th></tr>
                </thead>
                <tbody>
                    <?php if (!$backupFiles): ?>
                        <tr><td colspan="4" class="text-center text-muted py-5">
                            <i class="bi bi-archive fs-3 d-block mb-2"></i>Belum ada file backup tersimpan di server.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($backupFiles as $backup): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-filetype-sql text-danger fs-5"></i>
                                        <span class="fw-semibold text-break"><?= e($backup['name']) ?></span>
                                    </div>
                                </td>
                                <td><?= e(backup_format_size((int) $backup['size'])) ?></td>
                                <td><?= e(date('d M Y, H:i:s', (int) $backup['created'])) ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary rounded-3" href="<?= e(app_url('admin/backup_restore.php?download=' . rawurlencode($backup['name']))) ?>" aria-label="Download <?= e($backup['name']) ?>" title="Download">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <form method="post" class="d-inline backup-delete-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_backup">
                                        <input type="hidden" name="filename" value="<?= e($backup['name']) ?>">
                                        <button class="btn btn-sm btn-outline-danger rounded-3" type="submit" aria-label="Hapus <?= e($backup['name']) ?>" title="Hapus">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
document.getElementById('restoreForm').addEventListener('submit', function (event) {
    var input = document.getElementById('backupFile');
    if (!input.files.length) {
        event.preventDefault();
        return;
    }
    if (!window.confirm('Restore akan mengganti struktur dan data database saat ini. Snapshot pengaman akan dibuat terlebih dahulu. Lanjutkan?')) {
        event.preventDefault();
    }
});

document.querySelectorAll('.backup-delete-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm('Hapus file backup ini secara permanen?')) {
            event.preventDefault();
        }
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
