CREATE DATABASE IF NOT EXISTS parkir
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE parkir;

CREATE TABLE IF NOT EXISTS tb_user (
    id_user INT AUTO_INCREMENT PRIMARY KEY,
    nama_lengkap VARCHAR(50) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(100) NOT NULL,
    role ENUM('admin', 'petugas', 'owner') NOT NULL,
    status_aktif TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tb_tarif (
    id_tarif INT AUTO_INCREMENT PRIMARY KEY,
    jenis_kendaraan VARCHAR(20) NOT NULL UNIQUE,
    tarif_per_jam DECIMAL(10,0) NOT NULL
) ENGINE=InnoDB;

ALTER TABLE tb_tarif MODIFY jenis_kendaraan VARCHAR(20) NOT NULL;

CREATE TABLE IF NOT EXISTS tb_area_parkir (
    id_area INT AUTO_INCREMENT PRIMARY KEY,
    nama_area VARCHAR(50) NOT NULL UNIQUE,
    kapasitas INT NOT NULL,
    terisi INT NOT NULL DEFAULT 0,
    CONSTRAINT chk_area_kapasitas CHECK (kapasitas >= 0 AND terisi >= 0 AND terisi <= kapasitas)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tb_kendaraan (
    id_kendaraan INT AUTO_INCREMENT PRIMARY KEY,
    plat_nomor VARCHAR(15) NOT NULL UNIQUE,
    jenis_kendaraan VARCHAR(20) NOT NULL,
    warna VARCHAR(20) NOT NULL,
    pemilik VARCHAR(100) NOT NULL,
    id_user INT NOT NULL,
    CONSTRAINT fk_kendaraan_user FOREIGN KEY (id_user) REFERENCES tb_user(id_user)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tb_transaksi (
    id_parkir INT AUTO_INCREMENT PRIMARY KEY,
    id_kendaraan INT NOT NULL,
    waktu_masuk DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    waktu_keluar DATETIME NULL,
    id_tarif INT NOT NULL,
    durasi_jam INT NULL,
    biaya_total DECIMAL(10,0) NULL,
    status ENUM('masuk', 'keluar') NOT NULL DEFAULT 'masuk',
    id_user INT NOT NULL,
    id_area INT NOT NULL,
    INDEX idx_transaksi_masuk (waktu_masuk),
    INDEX idx_transaksi_keluar (waktu_keluar),
    INDEX idx_transaksi_status (status),
    CONSTRAINT fk_transaksi_kendaraan FOREIGN KEY (id_kendaraan) REFERENCES tb_kendaraan(id_kendaraan)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_transaksi_tarif FOREIGN KEY (id_tarif) REFERENCES tb_tarif(id_tarif)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_transaksi_user FOREIGN KEY (id_user) REFERENCES tb_user(id_user)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_transaksi_area FOREIGN KEY (id_area) REFERENCES tb_area_parkir(id_area)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tb_log_aktivitas (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL,
    aktivitas VARCHAR(100) NOT NULL,
    waktu_aktivitas DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_waktu (waktu_aktivitas),
    CONSTRAINT fk_log_user FOREIGN KEY (id_user) REFERENCES tb_user(id_user)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO tb_user (nama_lengkap, username, password, role, status_aktif)
SELECT 'Administrator', 'admin', SHA2('admin123', 256), 'admin', 1
WHERE NOT EXISTS (SELECT 1 FROM tb_user WHERE username = 'admin');

INSERT INTO tb_tarif (jenis_kendaraan, tarif_per_jam)
VALUES ('motor', 2000), ('mobil', 5000), ('lainnya', 5000)
ON DUPLICATE KEY UPDATE tarif_per_jam = VALUES(tarif_per_jam);

INSERT INTO tb_area_parkir (nama_area, kapasitas, terisi)
SELECT 'Area A', 50, 0
WHERE NOT EXISTS (SELECT 1 FROM tb_area_parkir WHERE nama_area = 'Area A');

CREATE OR REPLACE VIEW v_rekap_transaksi AS
SELECT
    t.id_parkir,
    t.waktu_masuk,
    t.waktu_keluar,
    t.durasi_jam,
    t.biaya_total,
    t.status,
    k.plat_nomor,
    k.jenis_kendaraan,
    k.warna,
    k.pemilik,
    a.nama_area,
    tr.tarif_per_jam
FROM tb_transaksi t
JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
JOIN tb_area_parkir a ON a.id_area = t.id_area
JOIN tb_tarif tr ON tr.id_tarif = t.id_tarif;

DROP PROCEDURE IF EXISTS sp_parkir_keluar;
DELIMITER //
CREATE PROCEDURE sp_parkir_keluar(IN p_id_parkir INT, IN p_id_user INT)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_status VARCHAR(10) DEFAULT NULL;
    DECLARE v_waktu_masuk DATETIME;
    DECLARE v_id_area INT;
    DECLARE v_tarif DECIMAL(10,0);
    DECLARE v_durasi INT;
    DECLARE v_biaya DECIMAL(10,0);
    DECLARE v_not_found TINYINT DEFAULT 0;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_not_found = 1;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;
    SELECT t.status, t.waktu_masuk, t.id_area, tr.tarif_per_jam
    INTO v_status, v_waktu_masuk, v_id_area, v_tarif
    FROM tb_transaksi t
    JOIN tb_tarif tr ON tr.id_tarif = t.id_tarif
    WHERE t.id_parkir = p_id_parkir
    FOR UPDATE;

    IF v_not_found = 1 OR v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaksi parkir tidak ditemukan';
    END IF;
    IF v_status <> 'masuk' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaksi sudah diselesaikan';
    END IF;

    SET v_durasi = GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, v_waktu_masuk, NOW()) / 3600));
    SET v_biaya = v_durasi * v_tarif;

    UPDATE tb_transaksi
    SET waktu_keluar = NOW(), durasi_jam = v_durasi, biaya_total = v_biaya, status = 'keluar'
    WHERE id_parkir = p_id_parkir;

    UPDATE tb_area_parkir
    SET terisi = GREATEST(0, terisi - 1)
    WHERE id_area = v_id_area;

    INSERT INTO tb_log_aktivitas (id_user, aktivitas)
    VALUES (p_id_user, CONCAT('Transaksi parkir keluar #', p_id_parkir));

    COMMIT;

    SELECT t.id_parkir, t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.biaya_total,
           k.plat_nomor, k.jenis_kendaraan, k.pemilik, a.nama_area
    FROM tb_transaksi t
    JOIN tb_kendaraan k ON k.id_kendaraan = t.id_kendaraan
    JOIN tb_area_parkir a ON a.id_area = t.id_area
    WHERE t.id_parkir = p_id_parkir;
END//
DELIMITER ;

-- Jalankan bagian DCL ini menggunakan akun MySQL berhak membuat user/grant.
CREATE USER IF NOT EXISTS 'admin_parkir'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_ADMIN';
CREATE USER IF NOT EXISTS 'petugas_parkir'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_PETUGAS';
CREATE USER IF NOT EXISTS 'owner_parkir'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_OWNER';

GRANT SELECT, INSERT, UPDATE, DELETE ON parkir.* TO 'admin_parkir'@'localhost';
GRANT SELECT ON parkir.tb_user TO 'petugas_parkir'@'localhost';
GRANT SELECT ON parkir.tb_kendaraan TO 'petugas_parkir'@'localhost';
GRANT INSERT (plat_nomor, jenis_kendaraan, warna, pemilik, id_user) ON parkir.tb_kendaraan TO 'petugas_parkir'@'localhost';
GRANT SELECT ON parkir.tb_tarif TO 'petugas_parkir'@'localhost';
GRANT SELECT ON parkir.tb_area_parkir TO 'petugas_parkir'@'localhost';
GRANT UPDATE (terisi) ON parkir.tb_area_parkir TO 'petugas_parkir'@'localhost';
GRANT SELECT, INSERT ON parkir.tb_transaksi TO 'petugas_parkir'@'localhost';
GRANT INSERT ON parkir.tb_log_aktivitas TO 'petugas_parkir'@'localhost';
GRANT EXECUTE ON PROCEDURE parkir.sp_parkir_keluar TO 'petugas_parkir'@'localhost';
GRANT SELECT ON parkir.v_rekap_transaksi TO 'owner_parkir'@'localhost';
GRANT SELECT (jenis_kendaraan) ON parkir.tb_tarif TO 'owner_parkir'@'localhost';
GRANT SELECT (nama_area) ON parkir.tb_area_parkir TO 'owner_parkir'@'localhost';
