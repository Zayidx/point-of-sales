import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Table from "@/Components/Dashboard/Table";
import {
    IconArrowLeft,
    IconPackage,
    IconPlus,
    IconTrash,
    IconShoppingCart,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(value);

export default function Create({ suppliers, products, ingredients = [], warehouses = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        supplier_id: "",
        warehouse_id: "",
        document_number: "",
        notes: "",
        items: [],
    });

    const [searchProduct, setSearchProduct] = useState("");
    const catalog = [
        ...products.map((product) => ({ item_type: "product", id: product.id, name: product.title, code: product.sku || "-", unit: "unit", unit_price: Number(product.buy_price) || 0, stock: product.stock })),
        ...ingredients.map((ingredient) => ({ item_type: "ingredient", id: ingredient.id, name: ingredient.name, code: ingredient.code, unit: ingredient.base_unit?.symbol || "satuan dasar", unit_price: Number(ingredient.default_unit_cost) || 0, stock: null })),
    ];
    const filteredProducts = catalog.filter((item) =>
        `${item.name} ${item.code}`.toLowerCase().includes(searchProduct.toLowerCase())
    );

    const addItem = (entry) => {
        if (data.items.some((item) => item.item_type === entry.item_type && item.item_id === entry.id)) {
            toast.error("Item sudah ada di daftar.");
            return;
        }
        setData("items", [
            ...data.items,
            {
                item_type: entry.item_type,
                item_id: entry.id,
                product_id: entry.item_type === "product" ? entry.id : null,
                ingredient_id: entry.item_type === "ingredient" ? entry.id : null,
                item_name: entry.name,
                item_code: `${entry.item_type === "ingredient" ? "Bahan" : "Produk"} · ${entry.code} · ${entry.unit}`,
                qty_ordered: 1,
                unit_price: entry.unit_price,
            },
        ]);
    };

    const removeItem = (index) => {
        setData(
            "items",
            data.items.filter((_, i) => i !== index)
        );
    };

    const updateItem = (index, key, value) => {
        const items = [...data.items];
        items[index] = { ...items[index], [key]: Number(value) || 0 };
        setData("items", items);
    };

    const submit = (e) => {
        e.preventDefault();
        if (data.items.length === 0) {
            toast.error("Tambahkan minimal satu item.");
            return;
        }
        post(route("purchase-orders.store"), {
            onError: () => toast.error("Gagal membuat purchase order"),
        });
    };

    const total = data.items.reduce((sum, item) => sum + item.qty_ordered * item.unit_price, 0);

    return (
        <>
            <Head title="Buat Pesanan Pembelian" />
            <div className="mb-6">
                <Link
                    href={route("purchase-orders.index")}
                    className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke daftar PO
                </Link>
                <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                    <IconShoppingCart size={28} className="text-primary-500" />
                    Buat Pesanan Pembelian
                </h1>
            </div>

            <form onSubmit={submit} className="max-w-5xl">
                <div className="space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Informasi PO</h2>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                            <div>
                                <label className="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-200">Pemasok</label>
                                <select
                                    value={data.supplier_id}
                                    onChange={(e) => setData("supplier_id", e.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="">Pilih Pemasok</option>
                                    {suppliers.map((s) => (
                                        <option key={s.id} value={s.id}>{s.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-200">Tujuan Gudang</label>
                                <select
                                    value={data.warehouse_id}
                                    onChange={(e) => setData("warehouse_id", e.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="">Pilih Gudang</option>
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>{w.code} — {w.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-200">Nomor Dokumen</label>
                                <input
                                    type="text"
                                    value={data.document_number}
                                    onChange={(e) => setData("document_number", e.target.value)}
                                    placeholder="Kosongkan untuk auto-generate"
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-200">Catatan</label>
                                <input
                                    type="text"
                                    value={data.notes}
                                    onChange={(e) => setData("notes", e.target.value)}
                                    placeholder="Catatan PO"
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                                {errors.notes && <p className="mt-1 text-xs text-danger-500">{errors.notes}</p>}
                            </div>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Item Pembelian</h2>
                        <div className="mb-4 flex gap-3">
                            <input
                                type="text"
                                value={searchProduct}
                                onChange={(e) => setSearchProduct(e.target.value)}
                                placeholder="Cari produk untuk ditambahkan..."
                                className="h-11 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                            />
                        </div>
                        {searchProduct && filteredProducts.length > 0 && (
                            <div className="mb-4 max-h-48 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                {filteredProducts.map((product) => (
                                    <button
                                        key={product.id}
                                        type="button"
                                        onClick={() => addItem(product)}
                                        className="flex w-full items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-left text-sm transition hover:border-primary-200 hover:bg-primary-50 dark:border-slate-700 dark:bg-slate-800 dark:hover:border-primary-700 dark:hover:bg-primary-950/20"
                                    >
                                        <div>
                                            <p className="font-medium text-slate-800 dark:text-slate-200">{product.name}</p>
                                            <p className="text-xs text-slate-500">{product.item_type === "ingredient" ? "Bahan baku" : "Produk jadi"} · {product.code}{product.stock !== null ? ` · Stok: ${product.stock}` : ""}</p>
                                        </div>
                                        <span className="text-xs text-slate-500 dark:text-slate-400">
                                            {formatCurrency(product.unit_price)}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                        {data.items.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 dark:border-slate-700">
                                            <th className="px-3 py-2 text-left font-semibold text-slate-700 dark:text-slate-200">Produk</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Qty</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Harga</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Subtotal</th>
                                            <th className="w-16 px-3 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.items.map((item, index) => (
                                            <tr key={index} className="border-b border-slate-100 dark:border-slate-800">
                                                <td className="px-3 py-3">
                                                    <p className="font-medium text-slate-800 dark:text-slate-200">{item.item_name}</p>
                                                    <p className="text-xs text-slate-500">{item.item_code}</p>
                                                </td>
                                                <td className="px-3 py-3 text-right">
                                                    <input
                                                        type="number"
                                                        min={item.item_type === "ingredient" ? "0.0001" : "1"}
                                                        step={item.item_type === "ingredient" ? "0.0001" : "1"}
                                                        value={item.qty_ordered}
                                                        onChange={(e) => updateItem(index, "qty_ordered", e.target.value)}
                                                        className="h-10 w-20 rounded-lg border border-slate-200 bg-slate-50 px-3 text-right text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                    />
                                                </td>
                                                <td className="px-3 py-3 text-right">
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step={item.item_type === "ingredient" ? "0.0001" : "100"}
                                                        value={item.unit_price}
                                                        onChange={(e) => updateItem(index, "unit_price", e.target.value)}
                                                        className="h-10 w-28 rounded-lg border border-slate-200 bg-slate-50 px-3 text-right text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                    />
                                                </td>
                                                <td className="px-3 py-3 text-right font-medium text-slate-800 dark:text-slate-200">
                                                    {formatCurrency(item.qty_ordered * item.unit_price)}
                                                </td>
                                                <td className="px-3 py-3 text-center">
                                                    <button
                                                        type="button"
                                                        onClick={() => removeItem(index)}
                                                        className="rounded-lg p-1.5 text-slate-400 transition hover:bg-danger-50 hover:text-danger-500"
                                                    >
                                                        <IconTrash size={16} />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t-2 border-slate-200 dark:border-slate-700">
                                            <td colSpan={3} className="px-3 py-3 text-right font-bold text-slate-800 dark:text-slate-200">Total</td>
                                            <td className="px-3 py-3 text-right font-bold text-primary-600 dark:text-primary-400">{formatCurrency(total)}</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        ) : (
                            <div className="rounded-xl border border-dashed border-slate-200 p-8 text-center dark:border-slate-700">
                                <IconPackage size={40} className="mx-auto text-slate-300 dark:text-slate-600" />
                                <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Cari produk di atas untuk ditambahkan ke PO.</p>
                            </div>
                        )}
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link
                            href={route("purchase-orders.index")}
                            className="flex h-11 items-center rounded-xl border border-slate-200 bg-white px-6 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        >
                            Batal
                        </Link>
                        <Button
                            type="submit"
                            icon={<IconPlus size={18} />}
                            className="bg-primary-500 hover:bg-primary-600 text-white shadow-lg shadow-primary-500/30"
                            label={processing ? "Menyimpan..." : "Simpan PO"}
                            disabled={processing}
                        />
                    </div>
                </div>
            </form>
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;
