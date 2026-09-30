import { Head } from "@inertiajs/react";
import PublicLayout from "@/Layouts/PublicLayout";
import {
    IconShoppingCart,
    IconBuildingWarehouse,
    IconTruckDelivery,
    IconReportMoney,
    IconUsers,
    IconChartBar,
    IconShieldLock,
    IconCreditCard,
    IconCheck,
    IconArrowRight,
    IconQrcode,
    IconApi,
} from "@tabler/icons-react";

const GITHUB_URL = "https://github.com/aryadwiputra/point-of-sales";

const modules = [
    {
        icon: IconShoppingCart,
        title: "POS & Transaksi",
        desc: "Inti dari Dimsum Weigu POS: kasir cepat, fleksibel, dan siap digunakan setiap hari.",
        screenshot: "/screenshots/02-pos-checkout.png",
        features: [
            "Cari produk menggunakan barcode atau kata kunci",
            "Pindai barcode dengan kamera pada PWA",
            "Keranjang beberapa menu yang dapat ditahan dan dilanjutkan",
            "Pembayaran tunai, transfer, Midtrans, Xendit, dan pembayaran nanti",
            "Satuan produk (pcs, kotak, kg, karton) dengan konversi stok",
            "Promosi: diskon, harga bertingkat, paket, dan beli-sejumlah-gratis-satu",
            "Persetujuan diskon dengan alur persetujuan",
            "Cetak struk termal 58/80 mm melalui WebUSB",
            "Penjualan luring disimpan dalam antrean dan disinkronkan saat kembali daring",
        ],
    },
    {
        icon: IconBuildingWarehouse,
        title: "Stok & Multi-Gudang",
        desc: "Kontrol penuh atas stok di semua gudang dan cabang.",
        screenshot: "/screenshots/07-warehouses.png",
        features: [
            "Produk, kategori, dan barcode",
            "Stok terpisah per gudang/cabang",
            "Transfer stok antar gudang (draf → kirim → terima)",
            "Stok opname per gudang",
            "Riwayat mutasi stok lengkap",
            "Pelacakan batch dan tanggal kedaluwarsa (FEFO)",
            "Produk gabungan atau paket",
            "Batas stok minimum dan rekomendasi PO otomatis",
            "Notifikasi stok menipis",
        ],
    },
    {
        icon: IconTruckDelivery,
        title: "Pengadaan & Pemasok",
        desc: "Rantai pengadaan yang rapi dari PO sampai hutang supplier.",
        screenshot: "/screenshots/09-purchase-orders.png",
        features: [
            "Pesanan pembelian (draf → dipesan → diterima sebagian → selesai)",
            "Penerimaan barang dengan pencatatan batch",
            "Retur kepada pemasok",
            "Kelola data pemasok",
            "Utang pemasok dengan analisis umur utang",
        ],
    },
    {
        icon: IconReportMoney,
        title: "Finance & Piutang",
        desc: "Arus uang terkontrol — piutang, hutang, dan pajak dalam satu tempat.",
        screenshot: "/screenshots/12-receivables.png",
        features: [
            "Piutang pelanggan dengan pembayaran bertahap",
            "Analisis umur piutang dan catatan penagihan",
            "Utang pemasok dan pencatatan pembayaran",
            "PPN 11% (di luar/termasuk harga) dan data NPWP",
            "Portal pelanggan untuk melihat faktur dan membayar daring",
        ],
    },
    {
        icon: IconUsers,
        title: "CRM & Loyalty",
        desc: "Tumbuhkan bisnis dengan pelanggan yang kembali lagi.",
        screenshot: "/screenshots/19-members.png",
        features: [
            "Manajemen pelanggan dan wilayah Indonesia",
            "Tingkat keanggotaan (reguler, perak, emas, platinum)",
            "Poin loyalitas yang dapat diperoleh dan ditukar",
            "Voucher pelanggan",
            "Segmentasi manual dan otomatis berdasarkan aturan",
            "Kampanye otomatis untuk pengingat dan promosi",
            "Integrasi WhatsApp untuk struk, pengingat, dan promosi otomatis",
        ],
    },
    {
        icon: IconChartBar,
        title: "Laporan & Insight",
        desc: "Keputusan bisnis berbasis data, bukan perasaan.",
        screenshot: "/screenshots/15-sales-report.png",
        features: [
            "Laporan penjualan dengan filter & ringkasan",
            "Laporan laba dan analisis margin",
            "Analisis jam sibuk, kinerja kasir, dan pelanggan berulang",
            "Faktur PDF, struk 80/58 mm, dan label pengiriman",
            "PDF piutang & hutang",
            "Ekspor data produk, pelanggan, dan transaksi ke Excel",
        ],
    },
    {
        icon: IconShieldLock,
        title: "Admin & Keamanan",
        desc: "Siapa punya akses apa, dan siapa mengubah apa — selalu jelas.",
        screenshot: "/screenshots/31-audit-logs.png",
        features: [
            "Pengaturan akses pengguna, peran, dan izin",
            "Catatan audit dengan rekaman sebelum dan sesudah perubahan",
            "Konfirmasi ulang kata sandi untuk tindakan sensitif",
            "Impor produk dan pelanggan dari Excel",
            "Pengelolaan versi aplikasi terpusat (APP_VERSION)",
            "Manajemen shift kasir",
        ],
    },
    {
        icon: IconCreditCard,
        title: "Pembayaran & Pengaturan",
        desc: "Terima pembayaran apa pun yang pelanggan Anda pakai.",
        screenshot: "/screenshots/24-payment-settings.png",
        features: [
            "Gerbang pembayaran Midtrans dan Xendit",
            "Rekening bank untuk transfer manual",
            "Beberapa daftar harga untuk kelompok pelanggan",
            "Target penjualan dan profil toko",
            "Pengaturan printer & pajak",
            "Antarmuka berbahasa Indonesia",
        ],
    },
    {
        icon: IconQrcode,
        title: "Dine-in QR & Self-Order",
        desc: "Pelanggan dapat membuka menu dari QR meja, membuat pesanan, dan memantau statusnya.",
        screenshot: "/screenshots/01-dashboard.png",
        features: [
            "Area dan floor plan meja",
            "QR code unik per meja",
            "Menu publik dan keranjang pelanggan",
            "Pembaruan status pesanan otomatis",
            "Petugas dapat menerima atau menolak pesanan",
            "Pembayaran saat ini melalui kasir",
        ],
    },
    {
        icon: IconApi,
        title: "API & Integrasi",
        desc: "Endpoint terautentikasi untuk integrasi aplikasi, perangkat, dan sinkronisasi POS.",
        screenshot: "/screenshots/01-dashboard.png",
        features: [
            "Autentikasi token menggunakan Sanctum",
            "Data produk, pelanggan, kategori, gudang, dan pemasok",
            "Keranjang, shift, pembayaran, transaksi, dan sinkronisasi luring",
            "Izin akses API mengikuti hak pengguna",
            "Dokumentasi API tersedia melalui Scramble",
        ],
    },
];

export default function Features() {
    return (
        <PublicLayout active="/fitur">
            <Head title="Fitur Lengkap — Dikasir" />

            {/* Header */}
            <section className="pt-20 pb-14 px-6 bg-gradient-to-b from-primary-50 dark:from-primary-950/40 to-transparent">
                <div className="max-w-7xl mx-auto text-center">
                    <h1 className="text-4xl md:text-5xl font-extrabold text-slate-900 dark:text-white">
                        Fitur Lengkap untuk Bisnis Nyata
                    </h1>
                    <p className="mt-5 text-lg text-slate-600 dark:text-slate-400 max-w-3xl mx-auto">
                        Fitur terintegrasi dari kasir harian sampai analitik lanjutan, gratis
                        digunakan dan open source di bawah lisensi MIT.
                    </p>
                    <div className="mt-8 flex flex-wrap justify-center gap-3">
                        {modules.map((m) => (
                            <a
                                key={m.title}
                                href={`#${m.title.toLowerCase().replace(/[^a-z0-9]+/g, "-")}`}
                                className="px-4 py-2 text-sm font-medium bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-600 dark:text-slate-300 hover:border-primary-300 dark:hover:border-primary-700 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                            >
                                {m.title}
                            </a>
                        ))}
                    </div>
                </div>
            </section>

            {/* Modules */}
            <section className="pb-20 px-6">
                <div className="max-w-7xl mx-auto space-y-20">
                    {modules.map((mod, idx) => (
                        <div
                            key={mod.title}
                            id={mod.title.toLowerCase().replace(/[^a-z0-9]+/g, "-")}
                            className={`flex flex-col ${idx % 2 === 1 ? "lg:flex-row-reverse" : "lg:flex-row"} gap-10 items-center`}
                        >
                            {/* Text */}
                            <div className="flex-1">
                                <div className="flex items-center gap-3 mb-4">
                                    <div className="w-11 h-11 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center">
                                        <mod.icon size={22} className="text-white" />
                                    </div>
                                    <h2 className="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white">
                                        {mod.title}
                                    </h2>
                                </div>
                                <p className="text-slate-600 dark:text-slate-400 mb-5">
                                    {mod.desc}
                                </p>
                                <ul className="space-y-2.5">
                                    {mod.features.map((f) => (
                                        <li key={f} className="flex items-start gap-2.5">
                                            <IconCheck
                                                size={18}
                                                className="text-emerald-500 mt-0.5 shrink-0"
                                            />
                                            <span className="text-sm text-slate-700 dark:text-slate-300">
                                                {f}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            {/* Screenshot */}
                            <div className="flex-1 w-full">
                                <div className="rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 shadow-xl bg-white dark:bg-slate-900">
                                    <img
                                        src={mod.screenshot}
                                        alt={`Screenshot ${mod.title}`}
                                        className="w-full"
                                        loading="lazy"
                                    />
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            {/* CTA */}
            <section className="pb-20 px-6">
                <div className="max-w-3xl mx-auto text-center">
                    <div className="bg-slate-900 dark:bg-slate-800 rounded-3xl p-10">
                        <h2 className="text-2xl md:text-3xl font-bold text-white mb-3">
                            Ada fitur yang kamu butuhkan?
                        </h2>
                        <p className="text-slate-400 mb-6">
                            Karena open source, fitur baru bisa datang dari siapa saja —
                            termasuk kamu.
                        </p>
                        <a
                            href={`${GITHUB_URL}/issues`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white font-semibold rounded-xl hover:from-primary-600 hover:to-primary-700 transition-all"
                        >
                            Ajukan ide fitur di GitHub
                            <IconArrowRight size={16} />
                        </a>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
