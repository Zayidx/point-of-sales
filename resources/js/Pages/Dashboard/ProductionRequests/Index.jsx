import React, { useMemo, useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import Swal from "sweetalert2";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Button from "@/Components/Dashboard/Button";
import { useAuthorization } from "@/Utils/authorization";
import { IconSearch, IconToolsKitchen2, IconClipboardList, IconPackage } from "@tabler/icons-react";

const statusLabels = {
    requested: "Menunggu persetujuan", approved: "Disetujui", rejected: "Ditolak",
    purchasing: "Dalam pembelian", sent_to_warehouse: "Dikirim ke gudang", received: "Diterima",
    in_production: "Sedang diproduksi", completed: "Selesai", cancelled: "Dibatalkan",
};
const currency = (value) => new Intl.NumberFormat("id-ID", {
    style: "currency", currency: "IDR", maximumFractionDigits: 0,
}).format(value || 0);

export default function Index({ requests, warehouses = [], menus = [], formDefaults }) {
    const { can } = useAuthorization();
    const [search, setSearch] = useState("");
    const form = useForm({
        request_key: formDefaults.request_key,
        warehouse_id: warehouses[0]?.id || "",
        product_id: menus[0]?.id || "",
        target_output: "",
        notes: "",
    });
    const selectedMenu = menus.find((menu) => String(menu.id) === String(form.data.product_id));
    const filteredMenus = useMemo(() => menus.filter((menu) =>
        menu.title.toLowerCase().includes(search.trim().toLowerCase())
    ), [menus, search]);

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
                title: "Tolak permintaan?", input: "textarea", inputLabel: "Alasan penolakan",
                inputValidator: (value) => !value?.trim() && "Alasan perlu diisi.",
                showCancelButton: true, confirmButtonText: "Tolak", cancelButtonText: "Batal",
            });
            if (!result.isConfirmed) return;
            reason = result.value;
        }
        router.post(route("production-requests.review", item.id), { decision, reason }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Permintaan Produksi" />
            <div className="mb-6 flex items-start gap-3">
                <span className="rounded-2xl bg-primary-50 p-3 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300"><IconToolsKitchen2 size={24} /></span>
                <div><h1 className="text-2xl font-bold text-slate-900 dark:text-white">Permintaan Produksi</h1><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Pilih menu, tentukan target, lalu kirim kebutuhan produksi ke gudang pusat.</p></div>
            </div>

            {can("production-requests-create") && (
                <form onSubmit={submit} className="mb-8 grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <section className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:p-5">
                        <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div><h2 className="font-semibold text-slate-900 dark:text-white">Pilih menu produksi</h2><p className="text-xs text-slate-500">Kebutuhan bahan dihitung otomatis dari resep aktif.</p></div>
                            <label className="relative w-full sm:max-w-64"><IconSearch size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Cari menu…" className="h-10 w-full rounded-xl border-slate-200 pl-9 text-sm dark:border-slate-700 dark:bg-slate-800" /></label>
                        </div>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {filteredMenus.map((menu) => {
                                const active = String(menu.id) === String(form.data.product_id);
                                return <button key={menu.id} type="button" onClick={() => form.setData("product_id", String(menu.id))} className={`min-h-28 rounded-xl border p-3 text-left transition hover:border-primary-400 hover:shadow-sm ${active ? "border-primary-500 bg-primary-50 ring-1 ring-primary-500 dark:bg-primary-900/20" : "border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800"}`}>
                                    <span className={`mb-3 inline-flex rounded-lg p-2 ${active ? "bg-primary-100 text-primary-700 dark:bg-primary-900/50 dark:text-primary-300" : "bg-slate-100 text-slate-500 dark:bg-slate-700"}`}><IconToolsKitchen2 size={18} /></span>
                                    <span className="block text-sm font-semibold text-slate-800 dark:text-slate-100">{menu.title}</span>
                                </button>;
                            })}
                            {filteredMenus.length === 0 && <p className="col-span-full rounded-xl border border-dashed p-8 text-center text-sm text-slate-500">Menu tidak ditemukan.</p>}
                        </div>
                    </section>

                    <aside className="h-fit rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 xl:sticky xl:top-20">
                        <div className="mb-4 flex items-center gap-2"><IconClipboardList size={19} className="text-primary-600" /><h2 className="font-semibold text-slate-900 dark:text-white">Rencana produksi</h2></div>
                        <div className="rounded-xl bg-slate-50 p-3 dark:bg-slate-800"><p className="text-xs text-slate-500">Menu dipilih</p><p className="mt-1 font-semibold text-slate-900 dark:text-white">{selectedMenu?.title || "Pilih menu di samping"}</p></div>
                        <label className="mt-4 block text-sm font-medium text-slate-700 dark:text-slate-200">Target produksi (porsi)
                            <input type="number" min="0.0001" step="0.0001" value={form.data.target_output} onChange={(event) => form.setData("target_output", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" placeholder="Contoh: 100" />
                            {form.errors.target_output && <span className="text-xs text-rose-600">{form.errors.target_output}</span>}
                        </label>
                        <div className="mt-4 flex items-center gap-2 rounded-xl border border-slate-100 p-3 text-sm dark:border-slate-700"><IconPackage size={18} className="shrink-0 text-slate-400" /><span className="text-slate-500">Lokasi bahan</span><strong className="ml-auto text-right text-slate-800 dark:text-slate-200">{warehouses[0]?.name || "Gudang pusat"}</strong></div>
                        <label className="mt-4 block text-sm font-medium text-slate-700 dark:text-slate-200">Catatan
                            <textarea rows="2" value={form.data.notes} onChange={(event) => form.setData("notes", event.target.value)} className="mt-1 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" placeholder="Opsional" />
                        </label>
                        {form.errors.product_id && <p className="mt-2 text-xs text-rose-600">{form.errors.product_id}</p>}
                        <Button type="submit" label={form.processing ? "Mengirim…" : "Kirim permintaan produksi"} disabled={form.processing || !selectedMenu || !form.data.target_output || !warehouses.length} className="mt-4 w-full bg-primary-600 text-white" />
                    </aside>
                </form>
            )}

            <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold text-slate-900 dark:text-white">Riwayat permintaan</h2><span className="text-xs text-slate-500">{requests.total ?? requests.data.length} permintaan</span></div>
            <div className="space-y-4">
                {requests.data.length === 0 && <div className="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500">Belum ada permintaan produksi.</div>}
                {requests.data.map((item) => (
                    <article key={item.id} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 sm:p-5">
                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"><div><div className="flex flex-wrap items-center gap-2"><h3 className="font-semibold text-slate-900 dark:text-white">{item.request_number}</h3><span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium dark:bg-slate-800">{statusLabels[item.status] || item.status}</span></div><p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{item.menu?.title} · {item.target_output} porsi · {item.warehouse?.name}</p><p className="mt-1 text-xs text-slate-500">Estimasi bahan {currency(item.estimated_material_cost)} · Pengaju {item.requested_by?.name}</p></div><div className="flex gap-2">{item.status === "requested" && can("production-requests-approve") && <><Button type="button" label="Tolak" onClick={() => review(item, "reject")} className="border border-rose-300 text-rose-700" /><Button type="button" label="Setujui" onClick={() => review(item, "approve")} className="bg-emerald-600 text-white" /></>}{item.status === "approved" && can("production-orders-create") && <Button type="button" label="Jadwalkan produksi" onClick={() => router.post(route("production-orders.schedule", item.id), {}, { preserveScroll: true })} className="bg-primary-600 text-white" />}</div></div>
                        <div className="mt-4 overflow-x-auto rounded-xl border border-slate-100 dark:border-slate-800"><table className="w-full min-w-[560px] text-left text-sm"><thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800"><tr><th className="px-4 py-3">Bahan</th><th className="px-4 py-3">Kebutuhan</th><th className="px-4 py-3">Stok</th><th className="px-4 py-3">Kurang</th><th className="px-4 py-3">Biaya</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-slate-800">{item.items.map((line) => <tr key={line.id}><td className="px-4 py-3 font-medium">{line.ingredient?.name}</td><td className="px-4 py-3">{line.required_quantity}</td><td className="px-4 py-3">{line.available_quantity}</td><td className={`px-4 py-3 ${Number(line.shortage_quantity) > 0 ? "font-semibold text-rose-600" : ""}`}>{line.shortage_quantity}</td><td className="px-4 py-3">{currency(line.estimated_cost)}</td></tr>)}</tbody></table></div>
                    </article>
                ))}
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
