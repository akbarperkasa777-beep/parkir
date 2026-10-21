# Sistem Informasi Parkir (PHP Native)

Aplikasi parkir PHP native dengan PDO/MySQL, Bootstrap 5, role admin/petugas/owner, pencatatan transaksi atomik, dan rekap transaksi.

## Instalasi XAMPP

1. Salin folder proyek ke `C:\xampp\htdocs\app-parkir2`.
2. Jalankan Apache dan MySQL dari XAMPP.
3. Buka phpMyAdmin, pilih **Import**, lalu impor `database.sql`. Skrip membuat database `parkir`, tabel, view, procedure, akun DB, tarif awal, area awal, dan akun administrator. Kolom jenis kendaraan pada tarif memakai teks bebas (maksimal 20 karakter); saran Motor, Mobil, dan Lainnya tetap tersedia.
4. Pastikan ekstensi PDO MySQL aktif di PHP (`pdo_mysql`).
5. Buka `http://localhost/app-parkir2/` untuk melihat landing page, atau `http://localhost/app-parkir2/login.php` untuk masuk.
6. Masuk dengan `admin` / `admin123`, kemudian ubah password awal lewat menu **Data User**.

Pengunjung dapat mendaftar sebagai **Petugas** atau **Owner** melalui halaman **Daftar**. Akun publik dibuat nonaktif dan Admin harus mengaktifkannya melalui **Data User** sebelum pengguna dapat login. Pembuatan akun Admin dibatasi untuk Admin yang sudah masuk. Petugas dapat mendaftarkan kendaraan baru bersamaan dengan transaksi parkir masuk.

> Sebelum impor, ganti nilai `GANTI_PASSWORD_ADMIN`, `GANTI_PASSWORD_PETUGAS`, dan `GANTI_PASSWORD_OWNER` di bagian DCL `database.sql` dengan password kuat. Setelah itu, samakan dengan konfigurasi PHP di bawah. Untuk penggunaan cepat lokal, koneksi PHP default memakai akun `root` XAMPP (password kosong), bukan akun DCL tersebut.

## Konfigurasi koneksi

Default koneksi memakai `localhost`, database `parkir`, dan akun XAMPP `root` dengan password kosong. Variabel lingkungan yang dapat digunakan:

- `DB_HOST`, `DB_NAME`
- `DB_ADMIN_USER`, `DB_ADMIN_PASSWORD`
- `DB_PETUGAS_USER`, `DB_PETUGAS_PASSWORD`
- `DB_OWNER_USER`, `DB_OWNER_PASSWORD`
- `DB_USER`, `DB_PASSWORD` sebagai fallback untuk ketiga role

Halaman login memakai koneksi admin untuk memverifikasi akun. Setelah login, koneksi aplikasi beralih ke akun database sesuai role. Akun DB petugas/owner dibuat dengan hak akses lebih terbatas pada bagian DCL `database.sql`. Owner membaca laporan dari `v_rekap_transaksi` dan mendapat akses baca hanya pada kolom jenis tarif dan nama area agar filter rekap selalu mencerminkan data master Admin, termasuk jenis/area yang belum memiliki transaksi. Jika database sudah dibuat sebelumnya, jalankan ulang perintah `GRANT INSERT (plat_nomor, jenis_kendaraan, warna, pemilik, id_user) ...` untuk memberi Petugas hak pencatatan kendaraan dan jalankan ulang seluruh perintah `GRANT` owner terbaru di bagian DCL; mengimpor ulang skrip tanpa menjalankan ulang bagian DCL tidak akan mengubah hak user yang sudah ada. Jika PHP Apache tidak mewarisi environment Windows, set variabel tersebut di konfigurasi Apache/PHP atau ubah default konfigurasi lokal di `koneksi.php`.

## Hak akses aplikasi

- **Admin:** Dashboard visual dengan tren transaksi/pendapatan 7 hari, monitoring keterisian area, transaksi terbaru, dan log aktivitas; manajemen area dengan denah slot proporsional; CRUD user/tarif; memantau kendaraan beserta status parkir dan mencari data kendaraan.
- **Petugas:** dashboard operasional dengan statistik harian, status area, pencarian kendaraan aktif, dan transaksi terkini; halaman transaksi masuk menampilkan denah slot berdasarkan transaksi aktif dan tarif sesuai jenis kendaraan yang diperbarui melalui polling; mendaftarkan kendaraan baru saat transaksi masuk, transaksi masuk/keluar, riwayat transaksi dengan filter tanggal, pembaruan kapasitas secara transaksional, dan cetak struk.
- **Owner:** dashboard finansial dengan perbandingan pendapatan bulanan, tren harian, kontribusi jenis kendaraan, kinerja area, dan jam sibuk; rekap berdasarkan waktu masuk/keluar dengan filter jenis dan area, export CSV yang dapat dibuka di Excel, serta cetak/Save as PDF dari browser.

Semua perubahan data aplikasi memakai prepared statements dan CSRF token. Perubahan admin dan transaksi masuk menggabungkan perubahan data dengan log aktivitas dalam satu transaksi. Procedure `sp_parkir_keluar` mengunci transaksi, menghitung durasi minimal satu jam dengan pembulatan ke atas, memperbarui slot, menyimpan log, dan meng-commit secara atomik.

Struk parkir menampilkan QR Code dengan nomor tiket `ID-PARKIR-{id}`. Halaman transaksi keluar dapat memindai QR melalui kamera pada HTTPS/localhost; proses keluar tetap memerlukan konfirmasi Petugas dan menggunakan procedure yang sama. Jika kamera tidak tersedia atau library gagal dimuat, tiket dapat dicari dengan nomor ID atau plat secara manual. QR dan scanner memerlukan koneksi untuk memuat library JavaScript dari CDN. Petugas dapat melihat riwayat seluruh transaksi dan mencetaknya dari menu **Riwayat Transaksi**, dengan filter tanggal berdasarkan waktu masuk.

Landing page membaca nama area dan kapasitas dari database serta mendukung Bahasa Indonesia dan English melalui pemilih bahasa; pilihan bahasa disimpan pada session. Teks landing page dikelola pada `includes/landing_translations.php`. Untuk menampilkan kontak operasional yang benar, ubah nilai `$supportEmail` dan `$supportPhone` di `index.php` serta alamat pada bagian kontak. Form pesan membuka aplikasi email pengguna (mailto), sehingga memerlukan aplikasi email yang dikonfigurasi di perangkat.

Admin dapat mengetik jenis kendaraan baru saat menambah tarif. Petugas juga dapat mengetik jenis yang sama saat mendaftarkan kendaraan; transaksi hanya bisa dicatat jika jenis kendaraan tersebut memiliki tarif aktif pada `tb_tarif`. Saat memperbarui database lama, jalankan `ALTER TABLE tb_tarif MODIFY jenis_kendaraan VARCHAR(20) NOT NULL;` satu kali agar jenis selain Motor/Mobil/Lainnya dapat disimpan.

Denah area Admin dan grafik okupansi dashboard mengambil jumlah kendaraan dari transaksi berstatus `masuk` dan memperbarui tampilan melalui polling AJAX. Database tidak menyimpan nomor petak fisik; petak berwarna dan nomor slot pada denah adalah ilustrasi, sementara jumlah okupansi dan plat yang ditampilkan bersumber dari transaksi aktif.

## Catatan deployment

Gunakan HTTPS dan password database yang unik pada server produksi. Jangan memakai password DCL contoh di server yang dapat diakses publik. Akun admin awal menggunakan hash SHA-256 hanya untuk bootstrap dan otomatis ditingkatkan ke `password_hash()` saat login pertama.
