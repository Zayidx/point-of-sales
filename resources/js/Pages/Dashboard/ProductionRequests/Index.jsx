import React from "react";
import { Head, router, useForm } from "@inertiajs/react";
import Swal from "sweetalert2";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Button from "@/Components/Dashboard/Button";
import { useAuthorization } from "@/Utils/authorization";

const statusLabels = {
    requested: "Menunggu approval",
    approved: "Disetujui",
    rejected: "Ditolak",
    purchasing: "Dalam pembelian",
    sent_to_warehouse: "Dikirim ke gudang",
    received: "Diterima",
    in_production: "Sedang diproduksi",
    completed: "Selesai",
    cancelled: "Dibatalkan",
};

const currency = (value) => new Intl.NumberFormat("id-ID", {
    style: "currency", currency: "IDR", maximumFractionDigits: 0,
}).format(value || 0);

export default function Index({ requests, warehouses = [], menus = [], formDefaults }) {
    const { can } = useAuthorization();
    const form = useForm({
        request_key: formDefaults.request_key,
        warehouse_id: warehouses[0]?.id || "",
        product_id: menus[0]?.id || "",
        target_output: "",
        notes: "",
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route("production-requests.store"), {
            preserveScroll: true,
            onSuccess: () => form.reset("target_output", "notes"),
        });
    };

    const review = async (item, decision) => {
        let reason;
        if (decision === "reject") {
            const result = await Swal.fire({
                title: "Tolak permintaan?",
                input: "textarea",
                inputLabel: "Alasan penolakan",
                inputValidator: (value) => !value?.trim() && "Alasan perlu diisi.",
                showCancelButton: true,
                confirmButtonText: "Tolak",
                cancelButtonText: "Batal",
            });
            if (!result.isConfirmed) return;
            reason = result.value;
        }

        router.post(route("production-requests.review", item.id), {
            decision,
            reason,
        }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Permintaan Produksi" />
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Permintaan Produksi</h1>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Rencana bahan dihitung dari versi resep terakhir dan stok gudang.</p>
            </div>

            {can("production-requests-create") && (
                <form onSubmit={submit} className="mb-6 grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-5">
                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200">Menu
                        <select value={form.data.product_id} onChange={(event) => form.setData("product_id", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800">
                            {menus.map((menu) => <option key={menu.id} value={menu.id}>{menu.title}</option>)}
                        </select>
                    </label>
                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200">Gudang
                        <select value={form.data.warehouse_id} onChange={(event) => form.setData("warehouse_id", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800">
                            {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                        </select>
                    </label>
                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200">Target produksi
                        <input type="number" min="0.0001" step="0.0001" value={form.data.target_output} onChange={(event) => form.setData("target_output", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" />
                        {form.errors.target_output && <span className="text-xs text-rose-600">{form.errors.target_output}</span>}
                    </label>
                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200 md:col-span-1">Catatan
                        <input value={form.data.notes} onChange={(event) => form.setData("notes", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" placeholder="Opsional" />
                    </label>
                    <div className="flex items-end"><Button type="submit" label={form.processing ? "Mengirim..." : "Kirim permintaan"} disabled={form.processing || !menus.length || !warehouses.length} className="w-full bg-primary-600 text-white" /></div>
                    {form.errors.product_id && <p className="text-sm text-rose-600 md:col-span-5">{form.errors.product_id}</p>}
                </form>
            )}

            <div className="space-y-4">
                {requests.data.length === 0 && <div className="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500">Belum ada permintaan produksi.</div>}
                {requests.data.map((item) => (
                    <article key={item.id} className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                            <div>
                                <div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold text-slate-900 dark:text-white">{item.request_number}</h2><span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium dark:bg-slate-800">{statusLabels[item.status] || item.status}</span></div>
                                <p className="mt-1 text-sm text-slate-500">{item.menu?.title} · {item.target_output} porsi · {item.warehouse?.name}</p>
                                <p className="mt-1 text-sm text-slate-500">Estimasi bahan: {currency(item.estimated_material_cost)} · Pengaju: {item.requested_by?.name}</p>
                            </div>
                            {item.status === "requested" && can("production-requests-approve") && <div className="flex gap-2"><Button type="button" label="Tolak" onClick={() => review(item, "reject")} className="border border-rose-300 text-rose-700" /><Button type="button" label="Setujui" onClick={() => review(item, "approve")} className="bg-emerald-600 text-white" /></div>}
                            {item.status === "approved" && can("production-orders-create") && <Button type="button" label="Jadwalkan produksi" onClick={() => router.post(route("production-orders.schedule", item.id), {}, { preserveScroll: true })} className="bg-primary-600 text-white" />}
                        </div>
                        <div className="mt-4 overflow-x-auto rounded-xl border border-slate-100 dark:border-slate-800">
                            <table className="w-full min-w-[560px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800"><tr><th className="px-4 py-3">Bahan</th><th className="px-4 py-3">Kebutuhan</th><th className="px-4 py-3">Stok</th><th className="px-4 py-3">Kekurangan</th><th className="px-4 py-3">Biaya</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{item.items.map((line) => <tr key={line.id}><td className="px-4 py-3 font-medium">{line.ingredient?.name}</td><td className="px-4 py-3">{line.required_quantity}</td><td className="px-4 py-3">{line.available_quantity}</td><td className={`px-4 py-3 ${Number(line.shortage_quantity) > 0 ? "font-semibold text-rose-600" : ""}`}>{line.shortage_quantity}</td><td className="px-4 py-3">{currency(line.estimated_cost)}</td></tr>)}</tbody></table>
                        </div>
                    </article>
                ))}
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
