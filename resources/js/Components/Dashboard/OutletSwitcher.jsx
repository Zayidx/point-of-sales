import React from "react";
import { router } from "@inertiajs/react";
import { IconBuildingStore, IconBuildingWarehouse } from "@tabler/icons-react";

export default function OutletSwitcher({ outlet, outlets = [], locked = false, isFinance = false, centralWarehouse }) {
    if (isFinance) {
        if (!centralWarehouse) return null;

        return (
            <div
                className="flex min-h-11 min-w-0 items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-2 py-1.5 dark:border-slate-700 dark:bg-slate-800 sm:gap-2 sm:px-3"
                title="Persediaan pusat yang dikelola bersama"
            >
                <IconBuildingWarehouse size={18} className="shrink-0 text-primary-500" />
                <span className="max-w-20 truncate text-xs font-medium text-slate-700 dark:text-slate-200 sm:max-w-36 sm:text-sm">
                    {centralWarehouse.name}
                </span>
            </div>
        );
    }

    if (outlets.length < 2) {
        return null;
    }

    return (
        <label className="flex min-h-11 min-w-0 items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-2 py-1.5 dark:border-slate-700 dark:bg-slate-800 sm:gap-2 sm:px-3">
            <IconBuildingStore size={18} className="shrink-0 text-primary-500" />
            <select
                value={outlet?.id || ""}
                disabled={locked}
                onChange={(event) => router.post(route("outlet.switch"), { outlet_id: event.target.value }, { preserveScroll: true })}
                className="max-w-20 border-0 bg-transparent p-0 text-xs font-medium text-slate-700 outline-none focus:ring-0 dark:text-slate-200 disabled:cursor-not-allowed disabled:opacity-60 sm:max-w-36 sm:text-sm"
                title={locked ? "Tutup shift aktif untuk mengganti outlet" : "Pilih outlet"}
            >
                {outlets.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.code} - {item.name}
                    </option>
                ))}
            </select>
        </label>
    );
}
