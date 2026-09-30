import React from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Button from "@/Components/Dashboard/Button";
import { useAuthorization } from "@/Utils/authorization";
import ImportErrorsNotice from "@/Components/Dashboard/ImportErrorsNotice";

export default function Index({ ingredients, categories = [], units = [], warehouses = [], canAdjustStock = false }) {
    const { flash = {} } = usePage().props;
    const { can } = useAuthorization();
    const form = useForm({
        code: "",
        name: "",
        ingredient_category_id: "",
        base_unit_id: units[0]?.id || "",
        default_unit_cost: 0,
        description: "",
    });
    const adjustment = useForm({
        ingredient_id: ingredients.data[0]?.id || "",
        warehouse_id: warehouses[0]?.id || "",
        quantity: "",
        reason: "",
        adjustment_key: crypto.randomUUID(),
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route("ingredients.store"), {
            preserveScroll: true,
            onSuccess: () => form.reset("code", "name", "ingredient_category_id", "default_unit_cost", "description"),
        });
    };

    const submitAdjustment = (event) => {
        event.preventDefault();
        adjustment.post(route("ingredients.adjust", adjustment.data.ingredient_id), {
            preserveScroll: true,
            onSuccess: () => adjustment.setData({ ...adjustment.data, quantity: "", reason: "", adjustment_key: crypto.randomUUID() }),
        });
    };

    return (
        <>
            <Head title="Bahan Baku" />
            <ImportErrorsNotice errors={flash.importErrors} />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Bahan Baku</h1>
                    <p className="mt-1 text-sm text-slate-500">Simpan bahan dalam satuan dasar untuk stok, resep, dan perhitungan HPP.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {can("ingredients-export") && <a href={route("export.ingredients")} className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium dark:border-slate-700">Ekspor Excel</a>}
                    {can("ingredients-import") && <>
                        <a href={route("import.template", "ingredients")} className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium dark:border-slate-700">Unduh template</a>
                        <input id="ingredient-import-file" type="file" accept=".xlsx,.xls,.csv" className="hidden" onChange={(event) => { const file = event.target.files?.[0]; if (file) router.post(route("import.ingredients"), { file }, { forceFormData: true, onFinish: () => { event.target.value = ""; } }); }} />
                        <button type="button" onClick={() => document.getElementById("ingredient-import-file")?.click()} className="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white">Impor bahan</button>
                    </>}
                    {can("inventory-opening-stock-import") && <>
                        <a href={route("import.template", "opening-stock")} className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium dark:border-slate-700 dark:text-slate-200">Templat saldo awal</a>
                        <input id="opening-stock-import-file" type="file" accept=".xlsx,.xls,.csv" className="hidden" onChange={(event) => { const file = event.target.files?.[0]; if (file) router.post(route("import.opening-stock"), { file }, { forceFormData: true, onFinish: () => { event.target.value = ""; } }); }} />
                        <button type="button" onClick={() => document.getElementById("opening-stock-import-file")?.click()} className="rounded-lg border border-primary-600 px-3 py-2 text-sm font-medium text-primary-700 dark:text-primary-300">Impor saldo awal</button>
                    </>}
                </div>
            </div>
            {can("inventory-opening-stock-import") && <p className="mb-4 text-xs text-slate-500">Saldo awal hanya dapat dimasukkan untuk barang dan gudang yang belum memiliki stok maupun riwayat pergerakan.</p>}
            <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-3">
                <label className="text-sm font-medium">Kode<input required maxLength="40" value={form.data.code} onChange={(event) => form.setData("code", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
                <label className="text-sm font-medium">Nama bahan<input required maxLength="150" value={form.data.name} onChange={(event) => form.setData("name", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
                <label className="text-sm font-medium">Kategori<select value={form.data.ingredient_category_id} onChange={(event) => form.setData("ingredient_category_id", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800"><option value="">Tanpa kategori</option>{categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                <label className="text-sm font-medium">Satuan dasar<select required value={form.data.base_unit_id} onChange={(event) => form.setData("base_unit_id", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800">{units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name} ({unit.symbol})</option>)}</select></label>
                <label className="text-sm font-medium">Estimasi harga per satuan<input required type="number" min="0" step="0.01" value={form.data.default_unit_cost} onChange={(event) => form.setData("default_unit_cost", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
                <div className="flex items-end"><Button type="submit" label={form.processing ? "Menyimpan..." : "Tambah bahan"} disabled={form.processing || !units.length} className="bg-primary-600 text-white" /></div>
                {Object.values(form.errors).length > 0 && <p className="text-sm text-rose-600 md:col-span-3">{Object.values(form.errors).join(" ")}</p>}
            </form>
            {canAdjustStock && warehouses.length > 0 && ingredients.data.length > 0 && (
                <form onSubmit={submitAdjustment} className="mb-6 grid gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/20 md:grid-cols-5">
                    <h2 className="text-base font-semibold md:col-span-5">Penyesuaian stok bahan baku</h2>
                    <select required value={adjustment.data.ingredient_id} onChange={(event) => adjustment.setData("ingredient_id", event.target.value)} className="h-11 rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800">{ingredients.data.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
                    <select required value={adjustment.data.warehouse_id} onChange={(event) => adjustment.setData("warehouse_id", event.target.value)} className="h-11 rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800">{warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select>
                    <input required type="number" step="0.0001" value={adjustment.data.quantity} onChange={(event) => adjustment.setData("quantity", event.target.value)} placeholder="Jumlah (+/-)" className="h-11 rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" />
                    <input required maxLength="500" value={adjustment.data.reason} onChange={(event) => adjustment.setData("reason", event.target.value)} placeholder="Alasan koreksi" className="h-11 rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" />
                    <Button type="submit" label={adjustment.processing ? "Menyimpan..." : "Catat koreksi"} disabled={adjustment.processing} className="bg-amber-600 text-white" />
                    {Object.values(adjustment.errors).length > 0 && <p className="text-sm text-rose-600 md:col-span-5">{Object.values(adjustment.errors).join(" ")}</p>}
                </form>
            )}
            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{ingredients.data.map((item) => <div key={item.id} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><p className="font-semibold">{item.name}</p><div className="mt-2 space-y-1">{item.stock_by_warehouse?.map((stock) => <p key={stock.warehouse_id} className="text-xs text-slate-500">{stock.warehouse_name}: {Number(stock.quantity).toLocaleString("id-ID", { maximumFractionDigits: 4 })} {item.base_unit?.symbol}</p>)}</div></div>)}</div>
            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table className="w-full min-w-[640px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800"><tr><th className="px-4 py-3">Kode</th><th className="px-4 py-3">Bahan</th><th className="px-4 py-3">Kategori</th><th className="px-4 py-3">Satuan dasar</th><th className="px-4 py-3">Harga acuan</th><th className="px-4 py-3">Status</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{ingredients.data.map((item) => <tr key={item.id}><td className="px-4 py-3 font-mono text-xs">{item.code}</td><td className="px-4 py-3 font-medium">{item.name}</td><td className="px-4 py-3">{item.category?.name || "—"}</td><td className="px-4 py-3">{item.base_unit?.symbol}</td><td className="px-4 py-3">{new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 2 }).format(item.default_unit_cost)}</td><td className="px-4 py-3">{item.is_active ? "Aktif" : "Nonaktif"}</td></tr>)}</tbody></table>
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
