import React from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { IconChartBar, IconFilter } from "@tabler/icons-react";

const money = (value) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);
const number = (value) => new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 }).format(value || 0);

export default function SalesByMenu({ filters, warehouses = [], items = [], showRevenue = false }) {
    const submit = (event) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get(route("reports.menu-sales.index"), Object.fromEntries(data.entries()), { preserveScroll: true, preserveState: true });
    };

    const totalPcs = items.reduce((sum, item) => sum + Number(item.pcs_sold || 0), 0);

    return (
        <>
            <Head title="Penjualan per Menu" />
            <div className="space-y-6">
                <header>
                    <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white"><IconChartBar size={26} className="text-primary-500" />Analisis Penjualan per Menu</h1>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Jumlah pcs dihitung dari kuantitas transaksi dikalikan isi satuan jual, dikurangi pengembalian yang selesai.</p>
                </header>

                <form onSubmit={submit} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2 lg:grid-cols-4">
                    <label className="text-sm text-slate-600 dark:text-slate-300">Dari tanggal<input name="start_date" type="date" defaultValue={filters.start_date || ""} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label className="text-sm text-slate-600 dark:text-slate-300">Sampai tanggal<input name="end_date" type="date" defaultValue={filters.end_date || ""} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label className="text-sm text-slate-600 dark:text-slate-300">Outlet<select name="warehouse_id" defaultValue={filters.warehouse_id || ""} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"><option value="">Semua outlet yang dapat diakses</option>{warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.outlet_name || warehouse.name}</option>)}</select></label>
                    <div className="flex items-end"><button className="inline-flex h-10 items-center gap-2 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white"><IconFilter size={16} />Terapkan</button></div>
                </form>

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                        <div><h2 className="font-semibold text-slate-900 dark:text-white">Menu terjual</h2><p className="text-sm text-slate-500">{items.length} menu · {number(totalPcs)} pcs bersih</p></div>
                        <span className="text-xs text-slate-500">Maksimal 200 menu teratas</span>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60"><tr><th className="px-5 py-3">Menu</th><th className="px-5 py-3 text-right">Pcs terjual</th><th className="px-5 py-3 text-right">Paket/porsi</th><th className="px-5 py-3 text-right">Transaksi</th>{showRevenue && <th className="px-5 py-3 text-right">Penjualan bersih</th>}</tr></thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {items.map((item) => <tr key={item.product_id}><td className="px-5 py-3"><p className="font-medium text-slate-900 dark:text-white">{item.title}</p><p className="text-xs text-slate-500">{item.sku || "-"}</p></td><td className="px-5 py-3 text-right font-semibold">{number(item.pcs_sold)}</td><td className="px-5 py-3 text-right">{number(item.packages_sold)}</td><td className="px-5 py-3 text-right">{number(item.transactions_count)}</td>{showRevenue && <td className="px-5 py-3 text-right">{money(item.net_sales)}</td>}</tr>)}
                                {!items.length && <tr><td colSpan={showRevenue ? 5 : 4} className="px-5 py-12 text-center text-slate-500">Belum ada penjualan pada filter ini.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}

SalesByMenu.layout = (page) => <DashboardLayout children={page} />;
