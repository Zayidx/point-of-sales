# Backup dan Pemulihan Database

Cadangkan database dengan `php artisan backup:database`. Command mendukung MySQL/MariaDB (`.sql.gz`), PostgreSQL (`.dump`), dan SQLite berbasis file (`.sqlite`). Database in-memory dan SQL Server ditolak. Proses backup tidak mengubah data aplikasi.

File lokal secara default disimpan di `storage/app/private/database-backups`. Gunakan `BACKUP_PATH` untuk mengubahnya. Isi `BACKUP_EXTERNAL_PATH` dengan direktori mount di luar server aplikasi agar file disalin keluar server. Jika mount tidak tersedia atau tidak dapat ditulis, backup dinyatakan gagal. Jangan arahkan tujuan eksternal ke direktori lokal biasa.

Retensi default 14 hari dikendalikan oleh `BACKUP_RETENTION_DAYS` dan dapat ditimpa dengan `--retain=30`. Command berjalan terjadwal setiap hari pukul 02.30 waktu aplikasi. Server harus menjalankan `php artisan schedule:run` setiap menit. Buat backup sebelum perubahan skema besar:

```bash
php artisan backup:database
php artisan backup:database --retain=30
```

## Pemulihan

Pulihkan ke database baru atau lingkungan pemulihan terisolasi terlebih dahulu. Hentikan penulisan aplikasi, pastikan versi kode dan migration sesuai, lalu validasi data dan login sebelum mengalihkan aplikasi. Simpan salinan kondisi database saat ini sebelum pemulihan.

Contoh perintah server Linux (ganti nama file, host, dan database tujuan):

```bash
# MySQL/MariaDB
gzip -dc backup-mysql-YYYYMMDD-HHMMSS.sql.gz | mysql --host=HOST --user=USER DATABASE_PEMULIHAN

# PostgreSQL; buat database pemulihan kosong terlebih dahulu
createdb --host=HOST --username=USER DATABASE_PEMULIHAN
pg_restore --host=HOST --username=USER --no-owner --no-privileges --dbname=DATABASE_PEMULIHAN backup-pgsql-YYYYMMDD-HHMMSS.dump

# SQLite file
cp backup-sqlite-YYYYMMDD-HHMMSS.sqlite database/pemulihan.sqlite
```

Biarkan utilitas database meminta kredensial secara interaktif; jangan simpan password dalam riwayat shell. Setelah hasil pemulihan terisolasi tervalidasi, alihkan konfigurasi koneksi secara terencana dan jalankan migration yang dibutuhkan.
