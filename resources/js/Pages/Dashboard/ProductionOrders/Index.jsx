import React, { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Button from "@/Components/Dashboard/Button";

const status = { planned: "Terjadwal", in_progress: "Sedang diproduksi", completed: "Selesai" };
const rupiah = (value) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);

function ProductionCard({ order, canOperate }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        actual_output: order.planned_output,
        actual_consumption: order.items.map((line) => ({ ingredient_id: line.ingredient_id, quantity: line.planned_quantity })),
    });
    const start = () => router.post(route("production-orders.start", order.id), {}, { preserveScroll: true });
    const complete = (event) => {
        event.preventDefault();
        form.post(route("production-orders.complete", order.id), { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    return <article className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div><div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold text-slate-900 dark:text-white">{order.order_number}</h2><span className="rounded-full bg-slate-100 px-3 py-1 text-xs dark:bg-slate-800">{status[order.status]}</span></div>
                <p className="mt-1 text-sm text-slate-500">{order.product?.title} · {order.planned_output} porsi · {order.warehouse?.name}</p>
                <p className="mt-1 text-sm text-slate-500">Estimasi HPP: {rupiah(order.request?.estimated_material_cost)}</p>
            </div>
            {canOperate && order.status === "planned" && <Button type="button" label="Mulai produksi" onClick={start} className="bg-primary-600 text-white" />}
            {canOperate && order.status === "in_progress" && !editing && <Button type="button" label="Catat hasil produksi" onClick={() => setEditing(true)} className="bg-emerald-600 text-white" />}
        </div>
        {editing && <form onSubmit={complete} className="mt-5 space-y-4 rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
            <label className="block text-sm font-medium">Hasil aktual (porsi)<input type="number" min="0.0001" step="0.0001" value={form.data.actual_output} onChange={(event) => form.setData("actual_output", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900" /></label>
            <div className="grid gap-3 sm:grid-cols-2">{order.items.map((line, index) => <label key={line.id} className="text-sm font-medium">Pemakaian {line.ingredient?.name} ({line.ingredient?.base_unit?.symbol || "satuan"})<input type="number" min="0.0001" step="0.0001" value={form.data.actual_consumption[index].quantity} onChange={(event) => form.setData("actual_consumption", form.data.actual_consumption.map((item, itemIndex) => itemIndex === index ? { ...item, quantity: event.target.value } : item))} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900" /></label>)}</div>
            {Object.values(form.errors).map((error) => <p key={error} className="text-sm text-rose-600">{error}</p>)}
            <div className="flex gap-2"><Button type="submit" label={form.processing ? "Menyimpan…" : "Selesaikan produksi"} disabled={form.processing} className="bg-emerald-600 text-white" /><Button type="button" label="Batal" onClick={() => setEditing(false)} className="border border-slate-300" /></div>
        </form>}
        {order.status === "completed" && <div className="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-200">Hasil {order.actual_output} porsi · Total biaya {rupiah(order.actual_material_cost)} · HPP aktual {rupiah(order.actual_unit_cost)}/porsi</div>}
    </article>;
}

export default function Index({ orders, canOperate }) {
    return <><Head title="Pesanan Produksi" /><div className="mb-6"><h1 className="text-2xl font-bold text-slate-900 dark:text-white">Pesanan Produksi</h1><p className="mt-1 text-sm text-slate-500">Catat pemakaian bahan dan hasil aktual. Stok diperbarui melalui buku persediaan.</p></div>
        <div className="space-y-4">{orders.data.length === 0 && <div className="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500">Belum ada pesanan produksi terjadwal.</div>}{orders.data.map((order) => <ProductionCard key={order.id} order={order} canOperate={canOperate} />)}</div>
        {orders.links?.length > 3 && <nav className="mt-6 flex flex-wrap gap-2">{orders.links.map((link, index) => <button key={index} disabled={!link.url} onClick={() => router.visit(link.url)} className={`rounded-lg px-3 py-2 text-sm ${link.active ? "bg-primary-600 text-white" : "bg-white text-slate-600"}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>}
    </>;
}
Index.layout = (page) => <DashboardLayout children={page} />;
