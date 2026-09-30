# RBAC, Users, Roles

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Mengatur kontrol akses berbasis role dan permission untuk semua modul dashboard.

## Fitur Saat Ini

- user management
- role management
- permission list
- route protection dengan middleware permission
- permission map dibagikan ke frontend via Inertia

## Halaman dan Route

- `dashboard/users`
- `dashboard/roles`
- `dashboard/permissions`

## Permission Umum

Setiap modul memakai permission sendiri, contohnya:

- `transactions-access`
- `sales-returns-*`
- `stock-opnames-*`
- `cashier-shifts-*`
- `audit-logs-access`

## Role Bawaan

`RoleSeeder` menyiapkan role berikut:

- **`super-admin`** — bypass seluruh permission, akses semua outlet.
- **`manager`**: akses pantau dashboard, laporan, transaksi, stok, produksi, dan operasional untuk
  cabang yang ditugaskan. Tidak dapat mengubah data atau membuka administrasi `users`/`roles`/
  `permissions`/`outlets`. Dibuat oleh `RoleSeeder::createManagerRole()`.
- **`finance`**: pembelian, persetujuan produksi, laporan keuangan, pembayaran utang, dan kas.
- **`warehouse`**: stok bahan/menu, penerimaan, produksi, transfer, dan pengembalian cabang.
- **`cashier`**: transaksi POS, buka/tutup shift, tambah pelanggan, bayar piutang/utang, dan
  memproses pesanan dine-in.
- Role per modul lain (mis. `products-access`, `transactions-access`) dibuat otomatis dari pola
  permission.

## Alur Otorisasi

1. permission diseed di `PermissionSeeder`
2. role disusun di `RoleSeeder`
3. akun awal dibuat melalui `UserSeeder` saat `php artisan db:seed`
4. route memakai middleware `permission:*`
5. frontend membaca map permission dari `HandleInertiaRequests`

## Catatan Super Admin

- user `super-admin` mendapat role `super-admin`
- backend memperlakukan role `super-admin` sebagai bypass permission yang konsisten untuk `can`, `canAny`, dan middleware Spatie
- `DatabaseSeeder` menjalankan `DimsumWeiguSeeder` dan `UserSeeder` untuk data awal usaha serta akun
  operasional (`manager@gmail.com`, `finance@gmail.com`, `warehouse@gmail.com`) dan empat akun kasir yang masing-masing dibatasi ke satu outlet (`cashier@gmail.com`, `cashier2@gmail.com`, `cashier3@gmail.com`, `cashier4@gmail.com`)
- cache permission Spatie harus di-reset saat seeding agar permission baru terbaca konsisten
- role lama `permission-access` dinormalisasi ke `permissions-access` saat seeding agar naming RBAC tidak ambigu

## Integrasi Frontend

Frontend membaca:

- `auth.permissions`
- `auth.super`

Ini dipakai untuk menampilkan atau menyembunyikan menu dan action tertentu.

Helper frontend utama:

- `resources/js/Utils/authorization.js`
- `resources/js/Utils/Permission.jsx`

## Batasan Saat Ini

- backend tetap menjadi sumber kebenaran utama
- frontend hanya untuk gating UI, bukan keamanan final

## File Sentral

- `database/seeders/PermissionSeeder.php`
- `database/seeders/RoleSeeder.php`
- `database/seeders/UserSeeder.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/Utils/Menu.jsx`
