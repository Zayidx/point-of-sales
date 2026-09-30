# Getting Started

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Panduan ini membantu developer baru menjalankan aplikasi dari nol sampai bisa login dan mengakses modul dashboard.

## Requirement Minimum

- PHP 8.3+ sesuai kebutuhan Laravel 13
- Composer
- Node.js 18+ + npm
- MySQL / MariaDB
- ekstensi PHP standar Laravel
 - Chrome/Chromium (untuk WhatsApp Gateway — opsional)

 Untuk deployment production yang memakai automation, siapkan queue worker dan scheduler Laravel.
 Jalankan `php artisan schedule:run` setiap menit. WhatsApp Gateway juga memerlukan service Node
 terpisah dan process manager seperti PM2.

## Langkah Setup

```bash
cp .env.example .env
composer install
PUPPETEER_SKIP_DOWNLOAD=true npm install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
# Start all local processes
composer run dev
# Open http://localhost:8000 after the server starts
```

## Urutan Bootstrapping yang Disarankan

1. isi konfigurasi database di `.env`
2. jalankan `php artisan migrate --seed`
3. jalankan `php artisan storage:link`
4. jalankan `composer run dev`
5. buka `http://localhost:8000` untuk homepage Dimsum Weigu
6. login ke `/login` menggunakan salah satu akun awal yang dibuat oleh seeder

## Seed Data

`DatabaseSeeder` membuat:

- permission
- role
- payment setting awal
- pengaturan dine-in
- gudang pusat `PUSAT` dan satu gudang stok aktif untuk setiap outlet
- profil usaha Dimsum Weigu, empat outlet, dan kategori awal
- akun admin, manager, finance, cashier, dan warehouse

`PUSAT` menyimpan stok pusat dan bukan outlet penjualan. Gudang outlet menerima stok melalui transfer dari PUSAT; transaksi mengurangi stok gudang outlet. Saat menutup shift, kasir menghitung sisa stok dan sistem mengembalikannya ke PUSAT. Setiap shift dan transaksi menyimpan outlet kasirnya agar laporan cabang terpisah. Wizard setup telah dihapus; konfigurasi awal disediakan melalui seeder.

Akun awal kasir per cabang (semua kata sandi awal `cashier123`): `cashier@gmail.com` (Galaxy BP), `cashier2@gmail.com` (Galaxy Hermina), `cashier3@gmail.com` (Pekayon Jaya), dan `cashier4@gmail.com` (Jalan Raya Pekayon). Akun lainnya: `manager@gmail.com` / `manager123`, `finance@gmail.com` / `finance123`, dan `warehouse@gmail.com` / `warehouse123`. Super-admin awal: `arya@gmail.com` / `password`. Untuk lingkungan yang dapat diakses publik, atur `SUPER_ADMIN_EMAIL` dan `SUPER_ADMIN_PASSWORD` sebelum menjalankan seeder.

Untuk menambahkan atau menyelaraskan empat akun kasir outlet saja tanpa mengubah akun lain, jalankan `php artisan db:seed --class=OutletCashierSeeder`. Seeder ini mempertahankan kata sandi akun yang sudah ada dan menetapkan setiap akun ke tepat satu cabang.

Untuk dataset demo lengkap secara eksplisit:

```bash
php artisan db:seed --class=DemoSeeder --force
```

`DemoSeeder` membuat outlet demo `MAL`, `TKB`, dan `PUT`, user demo, produk, transaksi, shift, purchasing, inventory, pricing, dine-in, dan feature coverage. Transaksi penjualan hanya dibuat pada gudang outlet penjualan; tidak ada panggilan gateway pembayaran nyata.

Akun demo yang dibuat:

| Email | Password | Role | Outlet |
|-------|----------|------|--------|
| `arya@gmail.com` | `password` | super-admin | Semua |
| `manager@gmail.com` | `password` | manager | MAL + TKB |
| `cashier@gmail.com` | `password` | cashier | MAL |

Rincian lengkap dataset demo ada di `docs/demo-data.md`.

Alias kompatibilitas berikut juga tersedia:

```bash
php artisan seed:demo --force
```

`DatabaseSeeder` aman untuk instalasi/produksi. Jangan menjalankan `DemoSeeder` atau `seed:demo` pada database produksi karena data operasional demo akan diregenerasi.

Catatan penting:

- fitur yang bergantung pada permission baru sebaiknya selalu diuji setelah `db:seed`
- jika permission terlihat tidak sinkron, logout-login ulang setelah seed selesai

## Setelah Aplikasi Jalan

Cek minimal:

1. `dashboard/settings/store`
2. `dashboard/settings/payments`
3. `dashboard/settings/bank-accounts`
4. `dashboard/settings/target`

## Skenario Multi-Cabang

Jika toko memiliki lebih dari satu cabang, gunakan skenario ini:

1. Seeder membuat `PUSAT` dan gudang stok aktif untuk masing-masing empat cabang Dimsum Weigu.
2. Akun super-admin memiliki akses ke semua outlet; manager dan finance mendapat semua outlet penjualan, cashier mendapat cabang pertama, dan warehouse mendapat pusat serta seluruh cabang.
3. Catat stok awal di `PUSAT`, lalu buat dan kirim transfer ke gudang outlet. Kasir menjual dari stok outlet dan mengembalikan sisa lewat hitung stok saat tutup shift.
4. Pengaturan per-cabang (logo, struk, payment gateway, bank account, printer, WhatsApp, target) ada di halaman Settings dengan outlet switcher di navbar.
5. Tutup shift sebelum pindah outlet — selector outlet terkunci selama shift kasir aktif.

## Tips Validasi Cepat

- buka dashboard utama
- buka transaksi kasir
- cek histori transaksi
- cek stock opname / cashier shift / audit logs jika migration fiturnya sudah ada

## Error Umum

- gambar tidak tampil: jalankan `php artisan storage:link`
- payment webhook tidak jalan: cek `APP_URL`
 - modul baru error 500: cek apakah migration fitur sudah dijalankan
 - reminder, reorder, atau campaign tidak berjalan: cek queue worker dan `schedule:run`
 - WhatsApp tidak terkirim: cek `WA_SERVICE_URL`, status device, `wa_enabled`, dan service Node
