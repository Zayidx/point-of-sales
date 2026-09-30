# Panduan Penggunaan Dimsum Weigu

Dokumen ini menjelaskan alur kerja harian Dimsum Weigu POS untuk empat outlet dan satu gudang pusat. Menu yang tampil dapat berbeda sesuai role dan outlet yang diberikan kepada akun.

## 1. Masuk ke aplikasi

1. Buka alamat aplikasi, lalu pilih **Masuk**.
2. Masukkan email dan kata sandi akun yang diberikan administrator.
3. Pastikan nama outlet aktif di bagian pemilih outlet sesuai cabang kerja. Akun kasir cabang hanya dapat bekerja pada outlet yang ditugaskan. Pemilih outlet terkunci selama shift masih terbuka.
4. Jika baru menerima akun awal, segera ganti kata sandi melalui profil dan jangan membagikan kredensial.

Pada instalasi awal, akun bawaan seeder adalah:

| Pengguna | Email | Kata sandi awal |
| --- | --- | --- |
| Manager | `manager@gmail.com` | `manager123` |
| Finance | `finance@gmail.com` | `finance123` |
| Gudang | `warehouse@gmail.com` | `warehouse123` |
| Kasir Cabang 1 — Galaxy BP | `cashier@gmail.com` | `cashier123` |
| Kasir Cabang 2 — Galaxy Hermina | `cashier2@gmail.com` | `cashier123` |
| Kasir Cabang 3 — Pekayon Jaya | `cashier3@gmail.com` | `cashier123` |
| Kasir Cabang 4 — Jalan Raya Pekayon | `cashier4@gmail.com` | `cashier123` |

Akun super-admin mengikuti `SUPER_ADMIN_EMAIL` dan `SUPER_ADMIN_PASSWORD` di konfigurasi server; nilai bawaan seeder hanya berlaku jika variabel tersebut tidak diganti. Administrator sebaiknya mengganti kredensial awal sebelum aplikasi digunakan bersama.

## 2. Alur kasir: buka shift sampai penjualan

Kasir memakai menu **Transaksi** (`/dashboard/transactions`). Jika belum ada shift aktif, halaman POS menampilkan formulir **Buka Shift Kasir**. Alternatifnya, buka **Operasional → Shift Kasir**.

### Membuka shift

1. Isi **Modal Awal** sesuai uang tunai yang benar-benar tersedia di laci.
2. Pilih gudang/cabang yang ditugaskan untuk akun tersebut. Jangan memilih gudang pusat PUSAT untuk transaksi outlet.
3. Isi catatan jika diperlukan, lalu tekan **Buka Shift Sekarang**.
4. Pastikan ringkasan shift aktif menunjukkan cabang yang benar. Satu kasir tidak dapat membuka shift kedua sebelum shift sebelumnya ditutup.

### Mencatat penjualan di POS

1. Buka **Transaksi**. POS hanya menampilkan barang yang tersedia di gudang shift aktif.
2. Cari menu dari kolom pencarian/kategori atau pindai barcode. Pilih produk untuk memasukkannya ke keranjang.
3. Periksa jumlah dan harga. Ubah kuantitas dengan tombol tambah/kurang; hapus baris yang keliru. Pilih pelanggan bila transaksi perlu dikaitkan ke pelanggan.
4. Pilih cara pembayaran yang sesuai, misalnya tunai, transfer/QRIS yang dikonfigurasi, pembayaran online GoFood, atau **Bayar Nanti** jika diizinkan dan memang menjadi piutang pelanggan. Untuk pembayaran tunai, masukkan uang yang diterima dan pastikan kembalian benar.
5. Periksa total dan tekan tombol penyelesaian transaksi. Tunggu konfirmasi berhasil sebelum melayani pesanan berikutnya.
6. Cetak atau buka struk/invoice dari halaman transaksi berhasil bila diperlukan.

Transaksi yang selesai mengurangi stok gudang outlet secara otomatis. Keranjang dapat ditahan (**hold**) untuk pelanggan yang belum siap membayar, lalu dilanjutkan kembali dari daftar transaksi tertahan. Jangan menutup shift untuk sekadar meninggalkan layar POS jika pekerjaan masih berlangsung.

### Retur dari pelanggan

1. Buka **Transaksi → Riwayat Transaksi**, lalu cari transaksi asal.
2. Pilih tindakan retur jika masih ada jumlah yang dapat diretur.
3. Buat draft, isi jumlah, alasan, dan apakah barang layak masuk kembali ke stok.
4. Tinjau lalu selesaikan retur sesuai izin role. Sistem mencatat refund/kredit dan dampak stok/piutang sesuai pilihan retur.

### Menutup shift

1. Setelah penjualan selesai, buka **Operasional → Shift Kasir** lalu masuk ke detail shift aktif.
2. Hitung uang fisik. Masukkan **Kas Aktual** dan catatan untuk selisih atau pengeluaran/pemasukan kas.
3. Hitung sisa fisik setiap menu yang ditampilkan pada bagian stok akhir. Isi semua baris, termasuk `0` untuk menu yang habis; jangan mengira stok sistem sebagai hitungan fisik.
4. Tinjau kas ekspektasi, kas aktual, selisih, dan ringkasan stok. Tekan **Tutup Shift** setelah angka diperiksa.

Pada outlet dengan gudang khusus, jumlah sisa yang dicatat saat penutupan dipindahkan dari gudang outlet kembali ke **PUSAT**. Shift penutupan juga menyimpan jumlah sistem dan jumlah aktual agar selisih bisa ditinjau. Selesaikan hitung stok dengan teliti sebelum menutup shift.

## 3. Alur gudang: penerimaan, pengambilan, dan retur stok

Gudang pusat **PUSAT** adalah sumber stok bersama bagi keempat outlet. Setiap cabang memiliki gudang stok outlet sendiri. Penjualan mengurangi stok outlet, bukan stok PUSAT secara langsung.

### Memasukkan barang dari supplier

1. Buat **Purchase Order (PO)** pada menu Pengadaan: pilih supplier, gudang tujuan (umumnya PUSAT), barang, jumlah, dan harga beli.
2. Tempatkan/pesan PO sesuai proses pembelian.
3. Saat barang fisik datang, buka **Penerimaan Barang**, pilih PO, lalu masukkan jumlah aktual yang diterima dan informasi batch/kedaluwarsa bila digunakan.
4. Simpan penerimaan. Stok gudang tujuan bertambah dan utang supplier dapat terbentuk untuk dibayar oleh finance.

### Mengirim stok dari PUSAT ke outlet

Halaman pengambilan stok adalah **Persediaan → Pengambilan Stok Outlet** (`/dashboard/stock-transfers`). Menu ini dikelola oleh role gudang.

1. Klik **Transfer Baru**.
2. Pilih **Gudang Asal: Gudang Bersama Dimsum Weigu (PUSAT)** dan gudang outlet tujuan yang benar.
3. Tambahkan setiap produk/menu yang dibawa dan jumlahnya. Pastikan jumlah cocok dengan barang fisik yang disiapkan.
4. Simpan transfer sebagai draf, periksa rincian, lalu gunakan tindakan **Kirim** saat barang benar-benar keluar dari PUSAT. Stok sumber berkurang pada tahap pengiriman.
5. Setelah jumlah fisik di outlet cocok, catat **Terima** pada dokumen transfer. Stok gudang outlet bertambah setelah penerimaan dikonfirmasi.

Periksa dokumen transfer dan jumlah barang sebelum dikirim atau diterima. Jangan membuat transfer baru untuk menggantikan dokumen yang masih dalam perjalanan; buka dokumen yang ada dan lanjutkan statusnya.

### Mengembalikan sisa stok outlet

Untuk pengembalian langsung dari halaman retur, kasir yang memiliki akses membuka **Persediaan → Pengembalian Stok Cabang**. Pilih cabang, masukkan produk dan jumlah fisik yang dikembalikan, lalu ajukan. Petugas gudang membuka halaman yang sama, memeriksa barang, mengisi jumlah diterima untuk **setiap item**, lalu mengonfirmasi penerimaan. Stok baru berpindah ke PUSAT setelah gudang mengonfirmasi.

Gunakan alur penutupan shift untuk hitung dan pengembalian rutin sisa stok harian. Gunakan halaman pengembalian cabang untuk retur terpisah yang perlu diajukan/diperiksa sebagai dokumen sendiri. Jangan mencatat satu jumlah yang sama melalui kedua alur.

> Transfer stok outlet saat ini mencatat produk/menu. Bahan baku seperti saus dalam gram atau mililiter dikelola melalui modul **Bahan Baku, Resep, dan Produksi** yang sudah dikonfigurasi; jangan memasukkan gram sebagai jumlah produk menu. Stok bahan dan stok produk POS adalah catatan yang berbeda.

## 4. Tugas dan alur tiap role

### Kasir outlet

- Login menggunakan akun kasir cabang masing-masing; cek outlet aktif sebelum bekerja.
- Buka dan tutup shift, proses POS, lihat riwayat transaksi, serta menangani retur yang diizinkan.
- Catat kas masuk/keluar shift bila ada dan hitung sisa stok menu saat penutupan.
- Melihat **Penjualan per Menu** untuk jumlah transaksi/porsi/pcs pada outlet yang boleh diakses. Kasir tidak melihat nilai penjualan finansial di laporan tersebut.
- Tidak mengelola user/role, pengadaan supplier, ataupun transfer gudang PUSAT dengan role bawaan.

### Petugas gudang

- Mengelola produk, satuan, stok gudang, penerimaan PO, transfer, stok opname, mutasi, bahan baku, resep, dan perintah produksi sesuai izin.
- Memproses transfer PUSAT → outlet: buat draf, kirim barang, lalu catat penerimaan setelah pengecekan fisik.
- Memeriksa dan mengonfirmasi retur stok cabang serta retur supplier.
- Menjaga agar setiap barang yang bergerak memiliki dokumen dan jumlah yang cocok dengan kondisi fisik.

### Finance

- Mengelola proses keuangan pengadaan dan supplier, utang/piutang, pembayaran, penerimaan dana, rekonsiliasi kas cabang, serta laporan keuangan yang diberikan role.
- Membuka laporan penjualan dan **Penjualan per Menu** lintas outlet yang diizinkan; laporan menu menampilkan jumlah pcs/paket dan nilai penjualan bersih.
- Meninjau serah-terima/pengambilan kas dan laporan operasional; tidak menggantikan pencatatan stok fisik yang dilakukan gudang/kasir.

### Manager

- Memantau operasional outlet, transaksi, shift, stok, pembelian/penerimaan, retur, laporan, dan audit yang diizinkan.
- Melihat penjualan per menu termasuk pcs serta nilai penjualan, dan membandingkan outlet pada periode yang dipilih.
- Menindaklanjuti selisih kas/stok dan memastikan tim menyelesaikan dokumen yang masih draf, dalam perjalanan, atau menunggu penerimaan.
- Role manager bawaan bersifat pemantauan untuk banyak data; tindakan buat/edit tertentu mungkin tidak tersedia. Hubungi administrator untuk permintaan akses tambahan.

### Super-admin / administrator

- Mengelola user, role, izin, data master, outlet, gudang, satuan, konfigurasi toko/pembayaran/printer, dan pengaturan lain sesuai akses.
- Menetapkan user ke outlet dan memeriksa bahwa akun kasir hanya ditugaskan pada cabang yang semestinya.
- Memberikan akses minimum yang dibutuhkan. Ubah password awal, jangan memakai akun administrator untuk transaksi kasir rutin, dan jangan berbagi akun antarpegawai.

## 5. Laporan dan kontrol operasional

- **Laporan → Penjualan per Menu**: atur rentang tanggal dan outlet. Pcs bersih berasal dari kuantitas penjualan × faktor konversi unit jual, dikurangi retur pelanggan yang selesai. Contoh: satu paket 6 pcs harus memiliki faktor konversi 6 agar laporan menampilkan enam pcs.
- **Riwayat Transaksi**: cari transaksi, cetak struk/invoice, dan mulai retur dari transaksi asal jika tersedia.
- **Operasional → Shift Kasir**: tinjau status shift, penjualan, kas yang diharapkan, kas aktual, dan selisih.
- **Persediaan → Stok Opname / Mutasi Stok**: gunakan untuk pemeriksaan fisik dan penelusuran perubahan stok, bukan untuk menggantikan transfer antar gudang.
- Bila menu tidak tampil atau akses ditolak, kemungkinan role tidak memiliki izin atau user tidak ditugaskan ke outlet/gudang tersebut. Hubungi administrator; jangan memakai akun role lain.

## 6. Aturan penting

1. Selalu pilih outlet/gudang yang tepat sebelum membuka shift atau membuat dokumen.
2. Penjualan hanya boleh diproses setelah shift aktif; jangan memakai shift kasir lain.
3. Catat perpindahan stok dengan transfer atau retur resmi, bukan dengan mengubah angka secara lisan.
4. Periksa jumlah dan barang secara fisik pada saat kirim/terima. Pengiriman mengurangi stok PUSAT; penerimaan menambah stok outlet.
5. Tutup shift setelah seluruh transaksi, kas, dan hitung stok diperiksa. Setelah shift ditutup, kasir tidak bisa melanjutkan transaksi pada shift tersebut.
6. Laporkan perbedaan harga, stok, kas, atau retur kepada manager/administrator sebelum membuat koreksi kedua.

Untuk prosedur teknis dan konfigurasi lebih lanjut, lihat `docs/getting-started.md`, `docs/multi-outlet.md`, `docs/features/pos-transactions.md`, `docs/features/cashier-shifts.md`, `docs/features/multi-warehouse.md`, dan `docs/features/sales-returns.md`.
