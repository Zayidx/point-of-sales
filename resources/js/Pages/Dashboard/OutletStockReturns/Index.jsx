import React, { useMemo, useState } from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";

function ReceiveReturn({ stockReturn }) {
    const initialItems = Object.fromEntries(
        stockReturn.items.map((item) => [item.id, item.quantity_requested]),
    );
    const form = useForm({ items: initialItems, notes: "" });

    const submit = (event) => {
        event.preventDefault();
        form.post(route("outlet-stock-returns.receive", stockReturn.id), {
            preserveScroll: true,
        });
    };

    return (
        <form
            onSubmit={submit}
            className="mt-4 space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800"
        >
            {stockReturn.items.map((item) => (
                <label
                    key={item.id}
                    className="flex items-center justify-between gap-3 text-sm"
                >
                    <span>
                        {item.product?.title}{" "}
                        <span className="text-slate-500">
                            · diajukan {item.quantity_requested}
                        </span>
                    </span>
                    <input
                        aria-label={`Jumlah diterima ${item.product?.title}`}
                        type="number"
                        min="0"
                        max={item.quantity_requested}
                        value={form.data.items[item.id]}
                        onChange={(event) =>
                            form.setData("items", {
                                ...form.data.items,
                                [item.id]: event.target.value,
                            })
                        }
                        className="h-9 w-24 rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"
                    />
                </label>
            ))}
            <label className="block text-sm">
                Catatan pemeriksaan
                <input
                    value={form.data.notes}
                    onChange={(event) => form.setData("notes", event.target.value)}
                    className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"
                />
            </label>
            <button
                type="submit"
                disabled={form.processing}
                className="rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-50"
            >
                {form.processing ? "Menyimpan…" : "Konfirmasi barang diterima"}
            </button>
            {Object.values(form.errors).map((error) => (
                <p key={error} className="text-xs text-rose-600">
                    {error}
                </p>
            ))}
        </form>
    );
}

function ReturnCard({ stockReturn, canReceive }) {
    const status =
        stockReturn.status === "pending"
            ? "Menunggu pemeriksaan gudang"
            : "Sudah diterima gudang";

    return (
        <article className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-semibold">{stockReturn.return_number}</p>
                    <p className="mt-1 text-sm text-slate-500">
                        {stockReturn.source_warehouse?.name} → {stockReturn.destination_warehouse?.name}
                    </p>
                </div>
                <span className="rounded-full bg-slate-100 px-3 py-1 text-xs dark:bg-slate-800">
                    {status}
                </span>
            </div>
            <div className="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                {stockReturn.items.map((item) => (
                    <div key={item.id} className="flex justify-between py-2 text-sm">
                        <span>{item.product?.title}</span>
                        <span>
                            {item.quantity_received ?? item.quantity_requested}{" "}
                            {item.quantity_received === null ? "diajukan" : "diterima"}
                        </span>
                    </div>
                ))}
            </div>
            {stockReturn.notes && (
                <p className="mt-2 text-sm text-slate-500">
                    Catatan cabang: {stockReturn.notes}
                </p>
            )}
            {stockReturn.status === "pending" && canReceive && (
                <ReceiveReturn stockReturn={stockReturn} />
            )}
        </article>
    );
}

export default function Index({
    returns,
    branches = [],
    canCreate,
    canReceive,
    requestKey,
}) {
    const [sourceId, setSourceId] = useState(branches[0]?.id ?? "");
    const selectedBranch = useMemo(
        () => branches.find((branch) => String(branch.id) === String(sourceId)),
        [branches, sourceId],
    );
    const [lines, setLines] = useState([{ product_id: "", quantity: 1 }]);
    const form = useForm({
        request_key: requestKey,
        source_warehouse_id: sourceId,
        items: lines,
        notes: "",
    });

    const setLine = (index, key, value) =>
        setLines((current) =>
            current.map((line, row) =>
                row === index ? { ...line, [key]: value } : line,
            ),
        );

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            source_warehouse_id: sourceId,
            items: lines,
        }));
        form.post(route("outlet-stock-returns.store"), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Pengembalian Stok Cabang" />
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                    Pengembalian Stok Cabang
                </h1>
                <p className="mt-1 text-sm text-slate-500">
                    Catat sisa produk dari cabang. Stok berpindah setelah gudang pusat
                    memeriksa penerimaan.
                </p>
            </div>
            {canCreate && (
                <form
                    onSubmit={submit}
                    className="mb-7 space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
                >
                    <h2 className="font-semibold">Ajukan pengembalian</h2>
                    <label className="block text-sm font-medium">
                        Cabang asal
                        <select
                            value={sourceId}
                            onChange={(event) => {
                                setSourceId(event.target.value);
                                form.setData("source_warehouse_id", event.target.value);
                                setLines([{ product_id: "", quantity: 1 }]);
                            }}
                            className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"
                        >
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>
                                    {branch.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    {lines.map((line, index) => (
                        <div key={index} className="grid gap-3 sm:grid-cols-[1fr_9rem_auto]">
                            <label className="text-sm">
                                Produk
                                <select
                                    value={line.product_id}
                                    onChange={(event) =>
                                        setLine(index, "product_id", event.target.value)
                                    }
                                    required
                                    className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"
                                >
                                    <option value="">Pilih produk</option>
                                    {(selectedBranch?.products ?? []).map((product) => (
                                        <option key={product.id} value={product.id}>
                                            {product.title} · stok {product.stock}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-sm">
                                Jumlah
                                <input
                                    type="number"
                                    min="1"
                                    max={
                                        (selectedBranch?.products ?? []).find(
                                            (product) =>
                                                String(product.id) === String(line.product_id),
                                        )?.stock ?? 1000000
                                    }
                                    value={line.quantity}
                                    onChange={(event) =>
                                        setLine(index, "quantity", event.target.value)
                                    }
                                    required
                                    className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"
                                />
                            </label>
                            <button
                                type="button"
                                onClick={() =>
                                    setLines((current) =>
                                        current.length > 1
                                            ? current.filter((_, row) => row !== index)
                                            : current,
                                    )
                                }
                                className="self-end rounded-lg px-3 py-2 text-sm text-rose-600"
                            >
                                Hapus
                            </button>
                        </div>
                    ))}
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() =>
                                setLines((current) => [
                                    ...current,
                                    { product_id: "", quantity: 1 },
                                ])
                            }
                            className="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-medium"
                        >
                            Tambah produk
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing || !selectedBranch?.products?.length}
                            className="rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                        >
                            {form.processing ? "Mengirim…" : "Ajukan ke gudang pusat"}
                        </button>
                    </div>
                    <label className="block text-sm">
                        Catatan
                        <input
                            value={form.data.notes}
                            onChange={(event) => form.setData("notes", event.target.value)}
                            className="mt-1 h-10 w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-800"
                        />
                    </label>
                    {Object.values(form.errors).map((error) => (
                        <p key={error} className="text-xs text-rose-600">
                            {error}
                        </p>
                    ))}
                </form>
            )}
            <section className="space-y-3">
                {returns.data.length ? (
                    returns.data.map((stockReturn) => (
                        <ReturnCard
                            key={stockReturn.id}
                            stockReturn={stockReturn}
                            canReceive={canReceive}
                        />
                    ))
                ) : (
                    <div className="rounded-2xl border border-dashed p-8 text-center text-sm text-slate-500">
                        Belum ada pengembalian stok.
                    </div>
                )}
            </section>
            {returns.links?.length > 3 && (
                <nav className="mt-5 flex flex-wrap gap-2">
                    {returns.links.map((link, index) => (
                        <Link
                            key={index}
                            href={link.url || "#"}
                            preserveScroll
                            className={`rounded-lg px-3 py-2 text-sm ${link.active ? "bg-primary-600 text-white" : "bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300"}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </nav>
            )}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
