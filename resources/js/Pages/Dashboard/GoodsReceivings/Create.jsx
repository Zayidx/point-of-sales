import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import {
    IconArrowLeft,
    IconTruckDelivery,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

export default function Create({ orders }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        request_key: crypto.randomUUID(),
        purchase_order_id: "",
        notes: "",
        items: [],
    });

    const [selectedPoId, setSelectedPoId] = useState("");
    const selectedOrder = orders.find((o) => o.id === Number(selectedPoId));

    const selectPO = (poId) => {
        setSelectedPoId(poId);
        const order = orders.find((o) => o.id === Number(poId));
        if (order) {
            const initialItems = order.items
                .filter((item) => {
                    const outstanding = item.qty_ordered - (item.qty_received || 0);
                    return outstanding > 0;
                })
                .map((item) => ({
                    purchase_order_item_id: item.id,
                    qty_sent: item.qty_ordered - (item.qty_received || 0),
                    product_title: item.product?.title || item.ingredient?.name || "Barang pesanan pembelian",
                    product_sku: item.product?.sku || item.ingredient?.code || item.ingredient?.base_unit?.symbol || "-",
                    item_type: item.ingredient_id ? "ingredient" : "product",
                    qty_accepted: item.qty_ordered - (item.qty_received || 0),
                    qc_status: "good",
                    condition_notes: "",
                    photo: null,
                    qty_ordered: item.qty_ordered,
                    qty_received_already: item.qty_received || 0,
                    outstanding: item.qty_ordered - (item.qty_received || 0),
                    qty_received: item.qty_ordered - (item.qty_received || 0),
                    notes: "",
                }));
            setData({
                request_key: data.request_key,
                purchase_order_id: poId,
                notes: "",
                items: initialItems,
            });
        }
    };

    const updateItem = (index, value) => {
        const items = [...data.items];
        const maxQty = Math.min(items[index].outstanding, Number(items[index].qty_sent || 0));
        items[index] = { ...items[index], qty_received: Math.min(Number(value) || 0, maxQty) };
        items[index].qty_accepted = Math.min(Number(items[index].qty_accepted), items[index].qty_received);
        setData("items", items);
    };

    const updateField = (index, key, value) => {
        const items = [...data.items];
        items[index] = { ...items[index], [key]: value };
        if (key === "qty_sent") {
            items[index].qty_received = Math.min(Number(items[index].qty_received), Number(value) || 0);
        }
        if (key === "qty_received") {
            items[index].qty_accepted = Math.min(Number(items[index].qty_accepted), Number(value) || 0);
        }
        if (key === "qc_status") {
            items[index].qty_accepted = ["good", "short"].includes(value) ? items[index].qty_received : 0;
        }
        setData("items", items);
    };

    const submit = (e) => {
        e.preventDefault();
        if (!data.purchase_order_id) {
            toast.error("Pilih purchase order terlebih dahulu.");
            return;
        }
        const validItems = data.items.filter((item) => item.qty_received > 0);
        if (validItems.length === 0) {
            toast.error("Terima minimal satu item.");
            return;
        }
        transform((payload) => ({ ...payload, items: validItems }));
        post(route("goods-receivings.store"), {
            onSuccess: () => {
                toast.success("Penerimaan barang berhasil dicatat");
                setData("request_key", crypto.randomUUID());
            },
            onError: () => toast.error("Gagal mencatat penerimaan"),
            onFinish: () => transform((payload) => payload),
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Terima Barang" />
            <div className="mb-6">
                <Link
                    href={route("goods-receivings.index")}
                    className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke daftar penerimaan
                </Link>
                <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                    <IconTruckDelivery size={28} className="text-primary-500" />
                    Terima Barang
                </h1>
            </div>

            <form onSubmit={submit} className="max-w-4xl">
                <div className="space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Pilih Pesanan Pembelian</h2>
                        <select
                            value={selectedPoId}
                            onChange={(e) => selectPO(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        >
                            <option value="">Pilih PO yang sudah dipesan...</option>
                            {orders.map((order) => (
                                <option key={order.id} value={order.id}>
                                    {order.document_number} - {order.supplier?.name || "Tanpa supplier"}
                                </option>
                            ))}
                        </select>
                        {errors.purchase_order_id && <p className="mt-1 text-xs text-danger-500">{errors.purchase_order_id}</p>}
                    </div>

                    {selectedOrder && data.items.length > 0 && (
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">
                                Barang Diterima
                            </h2>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 dark:border-slate-700">
                                            <th className="px-3 py-2 text-left font-semibold text-slate-700 dark:text-slate-200">Produk</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Jumlah di PO</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Jumlah dikirim</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Sudah Diterima</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Sisa</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Jumlah diterima</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Lolos pemeriksaan</th>
                                            <th className="px-3 py-2 text-left font-semibold text-slate-700 dark:text-slate-200">Kondisi</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Catatan</th>
                                            <th className="px-3 py-2 text-left font-semibold text-slate-700 dark:text-slate-200">Foto</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.items.map((item, index) => (
                                            <tr key={item.purchase_order_item_id} className="border-b border-slate-100 dark:border-slate-800">
                                                <td className="px-3 py-3">
                                                    <p className="font-medium text-slate-800 dark:text-slate-200">{item.product_title}</p>
                                                    <p className="text-xs text-slate-500">{item.product_sku}</p>
                                                </td>
                                                <td className="px-3 py-3 text-right">{item.qty_ordered}</td>
                                                <td className="px-3 py-3 text-right"><input type="number" min="0" max={item.outstanding} step={item.item_type === "ingredient" ? "0.0001" : "1"} value={item.qty_sent} onChange={(e) => updateField(index, "qty_sent", Number(e.target.value) || 0)} className="h-10 w-24 rounded-lg border border-slate-200 bg-slate-50 px-3 text-right text-sm dark:border-slate-700 dark:bg-slate-800" /></td>
                                                <td className="px-3 py-3 text-right text-slate-500">{item.qty_received_already}</td>
                                                <td className="px-3 py-3 text-right font-semibold text-warning-600">{item.outstanding}</td>
                                                <td className="px-3 py-3 text-right">
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step={item.item_type === "ingredient" ? "0.0001" : "1"}
                                                        max={Math.min(item.outstanding, item.qty_sent)}
                                                        value={item.qty_received}
                                                        onChange={(e) => updateItem(index, e.target.value)}
                                                        className="h-10 w-24 rounded-lg border border-slate-200 bg-slate-50 px-3 text-right text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                    />
                                                </td>
                                                <td className="px-3 py-3 text-right"><input type="number" min="0" max={item.qty_received} step={item.item_type === "ingredient" ? "0.0001" : "1"} value={item.qty_accepted} onChange={(e) => updateField(index, "qty_accepted", Number(e.target.value) || 0)} className="h-10 w-24 rounded-lg border border-slate-200 bg-slate-50 px-3 text-right text-sm dark:border-slate-700 dark:bg-slate-800" /></td>
                                                <td className="px-3 py-3"><select value={item.qc_status} onChange={(e) => updateField(index, "qc_status", e.target.value)} className="h-10 rounded-lg border-slate-200 bg-slate-50 text-xs dark:border-slate-700 dark:bg-slate-800"><option value="good">Baik</option><option value="damaged">Rusak</option><option value="short">Kurang</option><option value="wrong_item">Barang salah</option><option value="unusable">Tidak layak</option></select></td>
                                                <td className="px-3 py-3 text-right">
                                                    <input
                                                        type="text"
                                                        value={item.condition_notes || ""}
                                                        onChange={(e) => updateField(index, "condition_notes", e.target.value)}
                                                        placeholder="Kondisi barang"
                                                        className="h-10 w-32 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                    />
                                                </td>
                                                <td className="px-3 py-3"><input type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => updateField(index, "photo", e.target.files?.[0] || null)} className="max-w-40 text-xs" /></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {selectedOrder && data.items.length > 0 && (
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Catatan Penerimaan</h2>
                            <textarea
                                value={data.notes}
                                onChange={(e) => setData("notes", e.target.value)}
                                rows={3}
                                className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                placeholder="Catatan penerimaan barang (opsional)"
                            />
                        </div>
                    )}

                    <div className="flex justify-end gap-3">
                        <Link
                            href={route("goods-receivings.index")}
                            className="flex h-11 items-center rounded-xl border border-slate-200 bg-white px-6 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        >
                            Batal
                        </Link>
                        {selectedOrder && data.items.length > 0 && (
                            <Button
                                type="submit"
                                icon={<IconTruckDelivery size={18} />}
                                className="bg-success-500 hover:bg-success-600 text-white shadow-lg shadow-success-500/30"
                                label={processing ? "Menyimpan..." : "Konfirmasi Penerimaan"}
                                disabled={processing}
                            />
                        )}
                    </div>
                </div>
            </form>
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;
