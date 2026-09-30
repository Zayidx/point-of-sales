import React from "react";
import { Head, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Button from "@/Components/Dashboard/Button";

const rupiah = (value) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);
const reasons = [
    ["rusak", "Rusak"], ["basi", "Basi"], ["jatuh", "Jatuh"],
    ["tidak_layak_makan", "Tidak layak makan"], ["tidak_layak_jual", "Tidak layak jual"],
];
const reasonLabels = Object.fromEntries(reasons);

function Pager({ links = [] }) {
    if (links.length <= 3) return null;
    return <nav className="mt-3 flex flex-wrap gap-2">{links.map((link, index) => <button key={index} type="button" disabled={!link.url} onClick={() => router.visit(link.url)} className={`rounded-lg px-3 py-2 text-xs ${link.active ? "bg-primary-600 text-white" : "bg-white text-slate-600 dark:bg-slate-800 dark:text-slate-200"}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>;
}

export default function Index({ activeShift, expenses, wasteRecords, categories = [], products = [], requestKeys, canCreate }) {
    const expense = useForm({ request_key: requestKeys.expense, expense_category_id: categories[0]?.id || "", item_name: "", quantity: 1, unit_price: "", payment_source: "outlet_cash", notes: "", receipt_image: null });
    const waste = useForm({ request_key: requestKeys.waste, product_id: products[0]?.id || "", quantity: 1, reason: "rusak", notes: "" });
    const submitExpense = (event) => {
        event.preventDefault();
        expense.post(route("outlet-operations.expenses.store"), { forceFormData: true, preserveScroll: true, onSuccess: () => expense.setData({ ...expense.data, request_key: crypto.randomUUID(), item_name: "", quantity: 1, unit_price: "", notes: "", receipt_image: null }) });
    };
    const submitWaste = (event) => {
        event.preventDefault();
        waste.post(route("outlet-operations.waste.store"), { preserveScroll: true, onSuccess: () => waste.setData({ ...waste.data, request_key: crypto.randomUUID(), quantity: 1, notes: "" }) });
    };

    return <>
        <Head title="Operasional Cabang" />
        <div className="mb-6"><h1 className="text-2xl font-bold text-slate-900 dark:text-white">Operasional Cabang</h1><p className="mt-1 text-sm text-slate-500">Catat biaya operasional dan barang rusak selama shift.</p></div>
        {!activeShift && <div className="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Buka shift kasir untuk mencatat biaya atau barang terbuang.</div>}

        {canCreate && <div className="mb-8 grid gap-5 xl:grid-cols-2">
            <form onSubmit={submitExpense} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div><h2 className="font-semibold text-slate-900 dark:text-white">Biaya operasional</h2><p className="mt-1 text-xs text-slate-500">Pengeluaran tunai otomatis masuk perhitungan kas shift.</p></div>
                <label className="block text-sm font-medium">Kategori<select value={expense.data.expense_category_id} onChange={(event) => expense.setData("expense_category_id", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800">{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label>
                <div className="grid gap-3 sm:grid-cols-3"><label className="text-sm font-medium sm:col-span-1">Nama barang<input value={expense.data.item_name} onChange={(event) => expense.setData("item_name", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label><label className="text-sm font-medium">Jumlah<input type="number" min="0.0001" step="0.0001" value={expense.data.quantity} onChange={(event) => expense.setData("quantity", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label><label className="text-sm font-medium">Harga satuan<input type="number" min="0" step="1" value={expense.data.unit_price} onChange={(event) => expense.setData("unit_price", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label></div>
                <label className="block text-sm font-medium">Sumber pembayaran<select value={expense.data.payment_source} onChange={(event) => expense.setData("payment_source", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"><option value="outlet_cash">Kas outlet</option><option value="non_cash">Non-tunai / pribadi</option></select></label>
                <label className="block text-sm font-medium">Catatan<input value={expense.data.notes} onChange={(event) => expense.setData("notes", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
                <label className="block text-sm font-medium">Foto nota (opsional)<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => expense.setData("receipt_image", event.target.files[0] || null)} className="mt-1 block w-full text-sm" /></label>
                <p className="text-sm text-slate-500">Total: {rupiah(Number(expense.data.quantity || 0) * Number(expense.data.unit_price || 0))}</p>
                {Object.values(expense.errors).map((error) => <p key={error} className="text-xs text-rose-600">{error}</p>)}
                <Button type="submit" label={expense.processing ? "Menyimpan…" : "Simpan biaya"} disabled={expense.processing || !categories.length} className="bg-primary-600 text-white" />
            </form>

            <form onSubmit={submitWaste} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div><h2 className="font-semibold text-slate-900 dark:text-white">Barang rusak atau tidak layak jual</h2><p className="mt-1 text-xs text-slate-500">Stok dan nilai persediaan berkurang, dengan alasan tersimpan di audit.</p></div>
                <label className="block text-sm font-medium">Menu<select value={waste.data.product_id} onChange={(event) => waste.setData("product_id", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800">{products.map((product) => <option key={product.id} value={product.id}>{product.title}</option>)}</select></label>
                <div className="grid gap-3 sm:grid-cols-2"><label className="text-sm font-medium">Jumlah (satuan dasar)<input type="number" min="1" step="1" value={waste.data.quantity} onChange={(event) => waste.setData("quantity", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label><label className="text-sm font-medium">Alasan<select value={waste.data.reason} onChange={(event) => waste.setData("reason", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800">{reasons.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></div>
                <label className="block text-sm font-medium">Catatan<input value={waste.data.notes} onChange={(event) => waste.setData("notes", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
                {Object.values(waste.errors).map((error) => <p key={error} className="text-xs text-rose-600">{error}</p>)}
                <Button type="submit" label={waste.processing ? "Menyimpan…" : "Catat barang terbuang"} disabled={waste.processing || !products.length} className="bg-rose-600 text-white" />
            </form>
        </div>}

        <div className="grid gap-6 xl:grid-cols-2">
            <section><h2 className="mb-3 font-semibold text-slate-900 dark:text-white">Biaya terbaru</h2><div className="space-y-3">{expenses.data.length === 0 && <p className="rounded-xl border border-dashed p-6 text-sm text-slate-500">Belum ada biaya operasional.</p>}{expenses.data.map((row) => <article key={row.id} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><div className="flex justify-between gap-3"><div><p className="font-medium">{row.item_name}</p><p className="mt-1 text-xs text-slate-500">{row.category?.name} · {row.warehouse?.name} · {row.payment_source === "outlet_cash" ? "Kas outlet" : "Non-tunai"}</p></div><strong className="text-sm">{rupiah(row.total)}</strong></div>{row.receipt_path && <a className="mt-2 inline-block text-xs text-primary-600" href={`/storage/${row.receipt_path}`} target="_blank" rel="noreferrer">Lihat nota</a>}</article>)}</div><Pager links={expenses.links} /></section>
            <section><h2 className="mb-3 font-semibold text-slate-900 dark:text-white">Catatan produk terbuang terbaru</h2><div className="space-y-3">{wasteRecords.data.length === 0 && <p className="rounded-xl border border-dashed p-6 text-sm text-slate-500">Belum ada pencatatan produk terbuang.</p>}{wasteRecords.data.map((row) => <article key={row.id} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><div className="flex justify-between gap-3"><div><p className="font-medium">{row.product?.title} · {row.quantity} unit</p><p className="mt-1 text-xs text-slate-500">{reasonLabels[row.reason] || row.reason} · {row.warehouse?.name}</p></div><strong className="text-sm">{rupiah(row.total_cost)}</strong></div>{row.notes && <p className="mt-2 text-xs text-slate-500">{row.notes}</p>}</article>)}</div><Pager links={wasteRecords.links} /></section>
        </div>
    </>;
}

Index.layout = (page) => <DashboardLayout children={page} />;
