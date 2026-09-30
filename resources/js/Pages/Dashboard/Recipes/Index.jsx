import React from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Button from "@/Components/Dashboard/Button";
import Pagination from "@/Components/Dashboard/Pagination";
import { useAuthorization } from "@/Utils/authorization";
import ImportErrorsNotice from "@/Components/Dashboard/ImportErrorsNotice";

const emptyLine = (ingredientId = "", unitId = "") => ({ ingredient_id: ingredientId, quantity: "", unit_id: unitId });

export default function Index({ menus = [], ingredients = [], units = [] }) {
    const { flash = {} } = usePage().props;
    const { can } = useAuthorization();
    const menuRows = Array.isArray(menus) ? menus : (menus.data || []);
    const form = useForm({
        product_id: menuRows[0]?.id || "",
        yield_quantity: 1,
        notes: "",
        items: [emptyLine(ingredients[0]?.id || "", ingredients[0]?.base_unit_id || units[0]?.id || "")],
    });

    const addLine = () => form.setData("items", [...form.data.items, emptyLine(ingredients[0]?.id || "", ingredients[0]?.base_unit_id || units[0]?.id || "")]);
    const updateLine = (index, key, value) => form.setData("items", form.data.items.map((line, i) => i === index ? { ...line, [key]: value } : line));
    const updateIngredient = (index, value) => {
        const ingredient = ingredients.find((item) => String(item.id) === String(value));
        form.setData("items", form.data.items.map((line, i) => i === index ? { ...line, ingredient_id: value, unit_id: ingredient?.base_unit_id || "" } : line));
    };
    const submit = (event) => {
        event.preventDefault();
        form.post(route("recipes.store"), { preserveScroll: true, onSuccess: () => form.reset("notes") });
    };

    return (
        <>
            <Head title="Resep & HPP" />
            <ImportErrorsNotice errors={flash.importErrors} />
            <div className="mb-6 flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-bold text-slate-900 dark:text-white">Resep Menu</h1><p className="mt-1 text-sm text-slate-500">Setiap perubahan membuat versi resep baru, sehingga komposisi lama tetap tercatat.</p></div><div className="flex flex-wrap gap-2">{can("recipes-export") && <a href={route("export.recipes")} className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium dark:border-slate-700">Ekspor resep</a>}{can("recipes-import") && <><a href={route("import.template", "recipes")} className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium dark:border-slate-700">Unduh templat impor</a><input id="recipe-import-file" type="file" accept=".xlsx,.xls,.csv" className="hidden" onChange={(event) => { const file = event.target.files?.[0]; if (file) router.post(route("import.recipes"), { file }, { forceFormData: true, onFinish: () => { event.target.value = ""; } }); }} /><button type="button" onClick={() => document.getElementById("recipe-import-file")?.click()} className="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white">Impor resep</button></>}</div></div>
            <form onSubmit={submit} className="mb-7 space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div className="grid gap-4 sm:grid-cols-3">
                    <label className="text-sm font-medium">Menu<select required value={form.data.product_id} onChange={(event) => form.setData("product_id", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800">{menuRows.map((menu) => <option key={menu.id} value={menu.id}>{menu.title}</option>)}</select></label>
                    <label className="text-sm font-medium">Hasil resep (porsi)<input required type="number" min="0.0001" step="0.0001" value={form.data.yield_quantity} onChange={(event) => form.setData("yield_quantity", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
                    <label className="text-sm font-medium">Catatan<input value={form.data.notes} onChange={(event) => form.setData("notes", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
                </div>
                <div className="space-y-3">
                    {form.data.items.map((line, index) => <div key={index} className="grid items-end gap-3 sm:grid-cols-[1fr_1fr_150px_auto]">
                        <label className="text-sm font-medium">Bahan<select required value={line.ingredient_id} onChange={(event) => updateIngredient(index, event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800">{ingredients.map((ingredient) => <option key={ingredient.id} value={ingredient.id}>{ingredient.name}</option>)}</select></label>
                        <label className="text-sm font-medium">Satuan<input disabled value={units.find((unit) => String(unit.id) === String(line.unit_id))?.symbol || ""} className="mt-1 h-11 w-full rounded-xl border-slate-300 bg-slate-50 dark:border-slate-700 dark:bg-slate-800" /></label>
                        <label className="text-sm font-medium">Jumlah<input required type="number" min="0.0001" step="0.0001" value={line.quantity} onChange={(event) => updateLine(index, "quantity", event.target.value)} className="mt-1 h-11 w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800" /></label>
                        <Button type="button" label="Hapus" disabled={form.data.items.length === 1} onClick={() => form.setData("items", form.data.items.filter((_, i) => i !== index))} className="border border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-300" />
                    </div>)}
                </div>
                {Object.values(form.errors).length > 0 && <p className="text-sm text-rose-600">{Object.values(form.errors).join(" ")}</p>}
                <div className="flex flex-wrap justify-between gap-3"><Button type="button" label="Tambah bahan" onClick={addLine} disabled={!ingredients.length} className="border border-primary-300 text-primary-700" /><Button type="submit" label={form.processing ? "Menyimpan..." : "Simpan versi resep"} disabled={form.processing || !menuRows.length || !ingredients.length} className="bg-primary-600 text-white" /></div>
            </form>
            <div className="space-y-4">{menuRows.map((menu) => <section key={menu.id} className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 className="font-semibold">{menu.title}</h2>{menu.recipe_versions?.length ? menu.recipe_versions.map((recipe) => <div key={recipe.id} className="mt-3 border-t border-slate-100 pt-3 text-sm dark:border-slate-800"><p className="font-medium">Versi {recipe.version_number} · hasil {recipe.yield_quantity} porsi</p><ul className="mt-2 grid gap-x-6 gap-y-1 text-slate-500 sm:grid-cols-2">{recipe.items.map((line) => <li key={line.id}>{line.ingredient.name}: {line.quantity} {line.unit.symbol}</li>)}</ul></div>) : <p className="mt-2 text-sm text-slate-500">Belum ada resep.</p>}</section>)}</div>
            {menus.links && <Pagination links={menus.links} />}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
