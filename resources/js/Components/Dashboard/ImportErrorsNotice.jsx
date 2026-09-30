import React from "react";

export default function ImportErrorsNotice({ errors = [] }) {
    if (!Array.isArray(errors) || errors.length === 0) return null;

    return (
        <section role="alert" className="mb-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
            <h2 className="font-semibold">Laporan kesalahan impor ({errors.length} baris)</h2>
            <p className="mt-1">Baris yang valid tetap diproses. Perbaiki baris berikut lalu impor ulang.</p>
            <div className="mt-3 max-h-72 space-y-2 overflow-y-auto">
                {errors.map((failure, index) => (
                    <article key={`${failure.row}-${failure.field}-${index}`} className="rounded-lg bg-white/70 p-3 dark:bg-black/20">
                        <p className="font-medium">Baris {failure.row} · {failure.field}</p>
                        <ul className="mt-1 list-inside list-disc">
                            {(failure.errors || []).map((message, messageIndex) => <li key={messageIndex}>{message}</li>)}
                        </ul>
                        {failure.values && <details className="mt-2"><summary className="cursor-pointer">Data pada baris</summary><pre className="mt-1 overflow-x-auto whitespace-pre-wrap text-xs">{JSON.stringify(failure.values, null, 2)}</pre></details>}
                    </article>
                ))}
            </div>
        </section>
    );
}
