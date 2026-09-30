export const crmStatusLabel = (status) => ({
    draft: "Draf",
    ready: "Siap",
    processed: "Diproses",
    cancelled: "Dibatalkan",
    sent: "Terkirim",
    failed: "Gagal",
    pending: "Menunggu",
}[status] || status || "-");

export const crmTypeLabel = (type) => ({
    promo_broadcast: "Promosi",
    invoice_share: "Berbagi faktur",
    due_date_reminder: "Pengingat jatuh tempo",
    repeat_order_reminder: "Pengingat pemesanan ulang",
}[type] || type || "-");
