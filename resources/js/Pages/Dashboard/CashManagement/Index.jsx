import React from "react";
import { Head, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Button from "@/Components/Dashboard/Button";

const rupiah = (value) => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value || 0);
const handoverStatus = { pending: "Menunggu gudang", received: "Diterima" };
const pickupStatus = { requested: "Menunggu penyerahan", confirmed: "Sudah diambil" };

function HandoverRow({ handover, canConfirm }) {
    const form = useForm({ received_amount: handover.cashier_amount, notes: "" });
    const confirm = (event) => {
        event.preventDefault();
        form.post(route("cash-management.handovers.confirm", handover.id), { preserveScroll: true });
    };
    return <article className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-wrap justify-between gap-3"><div><p className="font-semibold">{handover.handover_number} · {handover.warehouse?.name}</p><p className="mt-1 text-xs text-slate-500">Kasir: {handover.cashier?.name} · {handoverStatus[handover.status]}</p><p className="mt-2 text-sm">Ekspektasi {rupiah(handover.expected_cash)} · Dilaporkan kasir {rupiah(handover.cashier_amount)}</p>{handover.status === "received" && <p className="mt-1 text-sm">Diterima {rupiah(handover.received_amount)} · Selisih {rupiah(handover.variance)}</p>}</div>
        {canConfirm && handover.status === "pending" && <form onSubmit={confirm} className="flex flex-wrap items-end gap-2"><label className="text-xs font-medium">Uang diterima<input type="number" min="0" step="1" value={form.data.received_amount} onChange={(event) => form.setData("received_amount", event.target.value)} className="mt-1 h-9 w-36 rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label><Button type="submit" label={form.processing ? "Menyimpan…" : "Konfirmasi"} disabled={form.processing} className="bg-primary-600 text-white" /></form>}
    </div></article>;
}

function PickupRow({ pickup, canConfirm }) {
    const form = useForm({ proof: null, notes: "" });
    const confirm = (event) => {
        event.preventDefault();
        form.post(route("cash-management.pickups.confirm", pickup.id), {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <article className="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div>
                <p className="font-semibold">{pickup.pickup_number} · {pickup.warehouse?.name}</p>
                <p className="mt-1 text-xs text-slate-500">Akuntan: {pickup.requester?.name} · {pickupStatus[pickup.status]}</p>
                <p className="mt-1 text-sm">{rupiah(pickup.amount)}{pickup.notes ? ` · ${pickup.notes}` : ""}</p>
                {pickup.proof_path && (
                    <a
                        href={route("cash-management.pickups.proof", pickup.id)}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-2 inline-block text-sm font-medium text-primary-600 hover:underline"
                    >
                        Lihat bukti serah-terima
                    </a>
                )}
            </div>
            {canConfirm && pickup.status === "requested" && (
                <form onSubmit={confirm} className="grid gap-2 sm:min-w-64">
                    <label className="text-xs font-medium">
                        Bukti serah-terima (JPG, PNG, atau PDF)
                        <input
                            type="file"
                            accept="image/jpeg,image/png,application/pdf"
                            required
                            onChange={(event) => form.setData("proof", event.target.files?.[0] ?? null)}
                            className="mt-1 block w-full text-xs file:mr-2 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 dark:file:bg-slate-800"
                        />
                    </label>
                    <label className="text-xs font-medium">
                        Catatan penerimaan
                        <input
                            value={form.data.notes}
                            onChange={(event) => form.setData("notes", event.target.value)}
                            className="mt-1 h-9 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"
                        />
                    </label>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                    >
                        {form.processing ? "Menyimpan…" : "Konfirmasi kas keluar"}
                    </button>
                    {Object.values(form.errors).map((error) => (
                        <p key={error} className="text-xs text-rose-600">{error}</p>
                    ))}
                </form>
            )}
        </article>
    );
}

export default function Index({ warehouses = [], closedShifts = [], handovers = [], pickups = [], canSubmitHandover, canConfirmHandover, canRequestPickup, canConfirmPickup, requestKeys }) {
    const handover = useForm({ request_key: requestKeys.handover, cashier_shift_id: closedShifts[0]?.id || "", notes: "" });
    const pickup = useForm({ request_key: requestKeys.pickup, warehouse_id: warehouses[0]?.id || "", amount: "", notes: "" });
    const submitHandover = (event) => {
        event.preventDefault();
        handover.post(route("cash-management.handovers.store"), { preserveScroll: true, onSuccess: () => handover.setData({ ...handover.data, request_key: crypto.randomUUID(), cashier_shift_id: "", notes: "" }) });
    };
    const requestCashPickup = (event) => {
        event.preventDefault();
        pickup.post(route("cash-management.pickups.store"), { preserveScroll: true, onSuccess: () => pickup.setData({ ...pickup.data, request_key: crypto.randomUUID(), amount: "", notes: "" }) });
    };

    return <><Head title="Pengelolaan Kas" /><div className="mb-6"><h1 className="text-2xl font-bold text-slate-900 dark:text-white">Pengelolaan Kas</h1><p className="mt-1 text-sm text-slate-500">Serah terima kas shift dan pengambilan uang gudang tercatat di ledger.</p></div>
        <section className="mb-7"><h2 className="mb-3 font-semibold">Saldo kas gudang</h2><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{warehouses.map((warehouse) => <article key={warehouse.id} className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><p className="text-sm text-slate-500">{warehouse.name}</p><p className="mt-2 text-xl font-bold">{rupiah(warehouse.cash_balance)}</p></article>)}</div></section>

        <div className="mb-7 grid gap-5 xl:grid-cols-2">
            {canSubmitHandover && <form onSubmit={submitHandover} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 className="font-semibold">Ajukan serah terima kas</h2>{closedShifts.length ? <><label className="block text-sm font-medium">Shift yang sudah ditutup<select value={handover.data.cashier_shift_id} onChange={(event) => handover.setData("cashier_shift_id", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800">{closedShifts.map((shift) => <option key={shift.id} value={shift.id}>{shift.warehouse?.name} · Ekspektasi {rupiah(shift.expected_cash)} · Aktual {rupiah(shift.actual_cash)}</option>)}</select></label><label className="block text-sm font-medium">Catatan<input value={handover.data.notes} onChange={(event) => handover.setData("notes", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label><Button type="submit" label={handover.processing ? "Mengirim…" : "Kirim ke gudang"} disabled={handover.processing} className="bg-primary-600 text-white" /></> : <p className="text-sm text-slate-500">Tidak ada shift tertutup yang menunggu serah terima.</p>}{Object.values(handover.errors).map((error) => <p key={error} className="text-xs text-rose-600">{error}</p>)}</form>}
            {canRequestPickup && <form onSubmit={requestCashPickup} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 className="font-semibold">Ajukan pengambilan kas</h2><label className="block text-sm font-medium">Gudang<select value={pickup.data.warehouse_id} onChange={(event) => pickup.setData("warehouse_id", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800">{warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name} · Saldo {rupiah(warehouse.cash_balance)}</option>)}</select></label><label className="block text-sm font-medium">Jumlah<input type="number" min="1" step="1" value={pickup.data.amount} onChange={(event) => pickup.setData("amount", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label><label className="block text-sm font-medium">Catatan<input value={pickup.data.notes} onChange={(event) => pickup.setData("notes", event.target.value)} className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label><Button type="submit" label={pickup.processing ? "Mengirim…" : "Kirim pengajuan"} disabled={pickup.processing || !warehouses.length} className="bg-primary-600 text-white" />{Object.values(pickup.errors).map((error) => <p key={error} className="text-xs text-rose-600">{error}</p>)}</form>}
        </div>

        <div className="grid gap-7 xl:grid-cols-2"><section><h2 className="mb-3 font-semibold">Serah terima outlet</h2><div className="space-y-3">{handovers.length ? handovers.map((row) => <HandoverRow key={row.id} handover={row} canConfirm={canConfirmHandover} />) : <p className="rounded-xl border border-dashed p-6 text-sm text-slate-500">Belum ada serah terima.</p>}</div></section><section><h2 className="mb-3 font-semibold">Pengambilan kas gudang</h2><div className="space-y-3">{pickups.length ? pickups.map((row) => <PickupRow key={row.id} pickup={row} canConfirm={canConfirmPickup} />) : <p className="rounded-xl border border-dashed p-6 text-sm text-slate-500">Belum ada pengajuan pengambilan kas.</p>}</div></section></div>
    </>;
}

Index.layout = (page) => <DashboardLayout children={page} />;
