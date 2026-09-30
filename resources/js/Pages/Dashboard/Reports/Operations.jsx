import React from "react";
import { Head, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Button from "@/Components/Dashboard/Button";

const money = (amount) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(amount || 0);
const quantity = (amount) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(amount || 0);
const dateText = (value) => value ? new Date(value).toLocaleDateString("id-ID") : "—";
const paymentLabels = { cash: "Tunai", qris: "QRIS", qris_1: "QRIS", qris_2: "QRIS", qris_3: "QRIS", bank_transfer: "Transfer bank", gofood: "GoFood / Online", online: "GoFood / Online", split: "Pembayaran gabungan" };
const movementLabels = { opening_stock: "Saldo awal", purchase_receipt: "Penerimaan pembelian", production_consumption: "Pemakaian produksi", production_output: "Hasil produksi", warehouse_to_outlet: "Gudang ke cabang", outlet_to_warehouse: "Cabang ke gudang", sale: "Penjualan", waste: "Barang rusak", stock_adjustment: "Penyesuaian stok", qc_reject: "Ditolak saat pemeriksaan" };

function Section({ title, children }) {
    return <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 className="mb-4 font-semibold text-slate-900 dark:text-white">{title}</h2>{children}</section>;
}

function SimpleTable({ headers, rows, empty = "Belum ada data pada rentang ini." }) {
    return <div className="overflow-x-auto"><table className="w-full min-w-[34rem] text-left text-sm"><thead><tr className="border-b border-slate-100 text-xs text-slate-500 dark:border-slate-800">{headers.map((header) => <th key={header} className="px-3 py-2 font-medium">{header}</th>)}</tr></thead><tbody>{rows.length ? rows : <tr><td colSpan={headers.length} className="px-3 py-6 text-center text-slate-500">{empty}</td></tr>}</tbody></table></div>;
}

export default function Operations({ filters, warehouses = [], summary, salesByPayment = [], salesByOutlet = [], expenseCategories = [], inventory = [], ledger = [], productionOrders = [], wasteRecords = [], cashHandovers = [], warehouseCash = [] }) {
    const form = useForm(filters);
    const applyFilters = (event) => {
        event.preventDefault();
        router.get(route("reports.operations.index"), form.data, { preserveState: true, preserveScroll: true, replace: true });
    };
    const cards = [
        ["Pendapatan", money(summary.revenue)], ["HPP terjual", money(summary.cogs)], ["Laba kotor", money(summary.gross_profit)],
            ["Biaya operasional", money(summary.expenses)], ["Kerugian barang terbuang", money(summary.waste_cost)], ["Hasil operasional", money(summary.net_operating_result)],
        ["Pembelian", money(summary.purchase_total)], ["Selisih stok", quantity(summary.stock_variance)],
    ];

    return <>
        <Head title="Laporan Operasional" />
        <div className="mb-6 flex flex-wrap items-end justify-between gap-3"><div><h1 className="text-2xl font-bold text-slate-900 dark:text-white">Laporan Operasional</h1><p className="mt-1 text-sm text-slate-500">Penjualan, persediaan, produksi, biaya, dan kas dalam satu ringkasan.</p></div><a href={route("reports.operations.index", { ...filters, export: 1 })} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">Ekspor Excel</a></div>
        <form onSubmit={applyFilters} className="mb-6 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1.4fr_auto] dark:border-slate-800 dark:bg-slate-900">
            <label className="text-sm">Dari tanggal<input type="date" value={form.data.start_date} onChange={(event) => form.setData("start_date", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
            <label className="text-sm">Sampai tanggal<input type="date" value={form.data.end_date} onChange={(event) => form.setData("end_date", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
            <label className="text-sm">Cabang/gudang<select value={form.data.warehouse_id || ""} onChange={(event) => form.setData("warehouse_id", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"><option value="">Semua lokasi yang dapat diakses</option>{warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select></label>
            <Button type="submit" label={form.processing ? "Memuat…" : "Terapkan filter"} disabled={form.processing} className="self-end bg-primary-600 text-white" />
            {Object.values(form.errors).map((error) => <p key={error} className="text-xs text-rose-600">{error}</p>)}
        </form>

        <div className="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{cards.map(([label, value]) => <article key={label} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><p className="text-sm text-slate-500">{label}</p><p className="mt-2 text-xl font-bold text-slate-900 dark:text-white">{value}</p></article>)}</div>
        <div className="mb-6 grid gap-5 xl:grid-cols-2">
            <Section title="Penjualan per outlet"><SimpleTable headers={["Outlet", "Transaksi", "Pendapatan"]} rows={salesByOutlet.map((row) => <tr key={row.warehouse_name} className="border-b border-slate-50 dark:border-slate-800"><td className="px-3 py-3">{row.outlet_name}</td><td className="px-3 py-3">{row.orders_count}</td><td className="px-3 py-3 font-medium">{money(row.total)}</td></tr>)} /></Section>
            <Section title="Penjualan per metode pembayaran"><SimpleTable headers={["Metode", "Transaksi", "Jumlah"]} rows={salesByPayment.map((row) => <tr key={row.payment_method} className="border-b border-slate-50 dark:border-slate-800"><td className="px-3 py-3">{paymentLabels[row.payment_method] ?? row.payment_method}</td><td className="px-3 py-3">{row.orders_count}</td><td className="px-3 py-3 font-medium">{money(row.total)}</td></tr>)} /></Section>
            <Section title="Biaya operasional per kategori"><SimpleTable headers={["Kategori", "Jumlah"]} rows={expenseCategories.map((row) => <tr key={row.name} className="border-b border-slate-50 dark:border-slate-800"><td className="px-3 py-3">{row.name}</td><td className="px-3 py-3 font-medium">{money(row.total)}</td></tr>)} /></Section>
            <Section title="Nilai dan jumlah persediaan"><SimpleTable headers={["Jenis persediaan", "Jumlah", "Nilai"]} rows={inventory.map((row) => <tr key={row.item_type} className="border-b border-slate-50 dark:border-slate-800"><td className="px-3 py-3">{row.item_type === "ingredient" ? "Bahan baku" : "Produk jadi"}</td><td className="px-3 py-3">{quantity(row.quantity)}</td><td className="px-3 py-3 font-medium">{money(row.value)}</td></tr>)} /></Section>
            <Section title="Performa dan HPP produksi"><div className="mb-3 grid grid-cols-2 gap-3 text-sm"><p>Perintah produksi <strong>{summary.production.orders_count}</strong></p><p>Biaya bahan <strong>{money(summary.production.material_cost)}</strong></p><p>Target output <strong>{quantity(summary.production.planned_output)}</strong></p><p>Output aktual <strong>{quantity(summary.production.actual_output)}</strong></p></div><SimpleTable headers={["Nomor", "Produk", "Target", "Aktual", "HPP aktual/unit"]} rows={productionOrders.map((row) => <tr key={row.id} className="border-b border-slate-50 dark:border-slate-800"><td className="px-3 py-3">{row.order_number}</td><td className="px-3 py-3">{row.product?.title}</td><td className="px-3 py-3">{quantity(row.planned_output)}</td><td className="px-3 py-3">{quantity(row.actual_output)}</td><td className="px-3 py-3">{money(row.actual_unit_cost)}</td></tr>)} /></Section>
            <Section title="Ringkasan serah terima dan kas gudang"><div className="mb-4 grid grid-cols-2 gap-3 text-sm"><p>Serah terima tertunda <strong>{summary.cash_handover.pending_count}</strong></p><p>Selisih kas <strong>{money(summary.cash_handover.variance)}</strong></p><p>Ekspektasi kas <strong>{money(summary.cash_handover.expected_cash)}</strong></p><p>Diterima gudang <strong>{money(summary.cash_handover.received_amount)}</strong></p></div><SimpleTable headers={["Gudang", "Kas masuk", "Kas keluar", "Perubahan"]} rows={warehouseCash.map((row) => <tr key={row.warehouse} className="border-b border-slate-50 dark:border-slate-800"><td className="px-3 py-3">{row.warehouse}</td><td className="px-3 py-3">{money(row.inflow)}</td><td className="px-3 py-3">{money(row.outflow)}</td><td className="px-3 py-3 font-medium">{money(row.balance_change)}</td></tr>)} /></Section>
            <Section title="Catatan barang terbuang"><SimpleTable headers={["Tanggal", "Cabang", "Produk", "Alasan", "Jumlah", "Biaya"]} rows={wasteRecords.map((row) => <tr key={row.id} className="border-b border-slate-50 dark:border-slate-800"><td className="px-3 py-3">{dateText(row.created_at)}</td><td className="px-3 py-3">{row.warehouse?.name}</td><td className="px-3 py-3">{row.product?.title}</td><td className="px-3 py-3">{row.reason}</td><td className="px-3 py-3">{quantity(row.quantity)}</td><td className="px-3 py-3">{money(row.total_cost)}</td></tr>)} /></Section>
            <Section title="Mutasi persediaan terbaru"><SimpleTable headers={["Tanggal", "Lokasi", "Referensi", "Barang", "Jenis", "Jumlah", "Biaya"]} rows={ledger.map((row) => <tr key={row.id} className="border-b border-slate-50 dark:border-slate-800"><td className="px-3 py-3">{dateText(row.created_at)}</td><td className="px-3 py-3">{row.warehouse_name}</td><td className="px-3 py-3">{row.reference_number}</td><td className="px-3 py-3">{row.item_name}</td><td className="px-3 py-3">{movementLabels[row.movement_type] ?? row.movement_type}</td><td className="px-3 py-3">{quantity(row.quantity)}</td><td className="px-3 py-3">{money(row.total_cost)}</td></tr>)} /></Section>
        </div>
        <Section title="Serah terima kas outlet"><SimpleTable headers={["Nomor", "Tanggal", "Lokasi", "Kasir", "Ekspektasi", "Aktual kasir", "Diterima", "Selisih", "Status"]} rows={cashHandovers.map((row) => <tr key={row.id} className="border-b border-slate-50 dark:border-slate-800"><td className="px-3 py-3">{row.handover_number}</td><td className="px-3 py-3">{dateText(row.created_at)}</td><td className="px-3 py-3">{row.warehouse?.name}</td><td className="px-3 py-3">{row.cashier?.name}</td><td className="px-3 py-3">{money(row.expected_cash)}</td><td className="px-3 py-3">{money(row.cashier_amount)}</td><td className="px-3 py-3">{money(row.received_amount)}</td><td className="px-3 py-3">{money(row.variance)}</td><td className="px-3 py-3">{row.status === "pending" ? "Menunggu" : "Diterima"}</td></tr>)} /></Section>
    </>;
}

Operations.layout = (page) => <DashboardLayout children={page} />;
