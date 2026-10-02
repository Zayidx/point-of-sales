import { useEffect, useState } from "react";
import { Head } from "@inertiajs/react";
import {
    IconArrowDown,
    IconArrowUpRight,
    IconClock,
    IconMapPin,
    IconPhone,
} from "@tabler/icons-react";

const formatPrice = (price) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(price || 0);

const menuCategories = [
    {
        id: "dimsum-kukus", title: "Dimsum",
        items: [
            { name: "Dimsum Ori", variants: [{ label: "4 pcs", price: 16000 }, { label: "6 pcs", price: 24000, recommended: true }, { label: "8 pcs", price: 30000 }] },
            { name: "Dimsum Mentai", variants: [{ label: "4 pcs", price: 21000 }, { label: "6 pcs", price: 28000, recommended: true }, { label: "8 pcs", price: 38000 }] },
            { name: "Dimsum Cheesemelt", variants: [{ label: "4 pcs", price: 25000 }, { label: "6 pcs", price: 35000 }, { label: "8 pcs", price: 48000 }] },
            { name: "Dimsum Mix", variants: [{ label: "6 pcs", price: 30000 }, { label: "8 pcs", price: 45000 }] },
        ],
    },
    {
        id: "lumpia", title: "Lumpia",
        items: [
            { name: "Lumpia Original", variants: [{ label: "3 pcs", price: 24000 }, { label: "5 pcs", price: 36000 }] },
            { name: "Lumpia Mentai", variants: [{ label: "3 pcs", price: 28000 }, { label: "5 pcs", price: 40000 }] },
        ],
    },
    {
        id: "chiquro", title: "Chiquro",
        items: [
            { name: "Chiquro Garlic", variants: [{ label: "Per porsi", price: 26000 }] },
            { name: "Chiquro Lava", variants: [{ label: "Per porsi", price: 26000 }] },
            { name: "Chiquro Mentai", variants: [{ label: "Per porsi", price: 26000, recommended: true }] },
            { name: "Chiquro Cheese", variants: [{ label: "Per porsi", price: 28000 }] },
        ],
    },
    {
        id: "paket-besar", title: "Paket Besar",
        items: [
            { name: "Paket Besar Dimsum Mix", variants: [{ label: "Paket", price: 90000 }] },
            { name: "Paket Besar Dimsum Mentai", variants: [{ label: "Paket", price: 85000 }] },
            { name: "Paket Besar Dimsum Cheesemelt", variants: [{ label: "Paket", price: 100000 }] },
        ],
    },
];

const contact = {
    phone: "0895343256336",
    email: "Weiguowner@gmail.com",
};
const GOFOOD_URL = "https://gofood.co.id/";
const CS_WHATSAPP = "62895343256336";

const branches = [
    {
        name: "Cabang 1 · Galaxy",
        address: "Di depan SPBU BP, Galaxy",
        hours: [{ days: "Setiap hari", time: "14.00–00.00" }],
        map: "https://maps.app.goo.gl/u9Gg5ufsxPXNCmwd9",
    },
    {
        name: "Cabang 2 · Galaxy",
        address: "Di samping RS Hermina, Galaxy",
        hours: [{ days: "Setiap hari", time: "08.00–18.00" }],
        map: "https://maps.app.goo.gl/xyGKHdRHhcAeBMkA7",
    },
    {
        name: "Cabang 3 · Pekayon Jaya",
        address: "Pekayon Jaya",
        hours: [
            { days: "Senin–Sabtu", time: "14.00–22.00" },
            { days: "Minggu", time: "06.00–16.00" },
        ],
        map: "https://maps.app.goo.gl/vBkAyD4WVhMoQUvm6",
    },
    {
        name: "Cabang 4 · Jalan Raya Pekayon",
        address: "Di samping Pakuwon Mall Bekasi",
        hours: [{ days: "Setiap hari", time: "14.30–22.15" }],
        map: "https://maps.app.goo.gl/6efBgdCTcRjrqytH7",
    },
];

function MenuPriceList() {
    const [activeCategoryId, setActiveCategoryId] = useState(menuCategories[0].id);
    const activeCategory = menuCategories.find((category) => category.id === activeCategoryId);

    return (
        <>
            <div role="tablist" aria-label="Kategori menu" className="scroll-reveal -mx-5 mb-6 flex gap-2 overflow-x-auto px-5 pb-2 sm:mx-0 sm:px-0">
                {menuCategories.map((category) => (
                    <button
                        key={category.id}
                        id={`tab-${category.id}`}
                        type="button"
                        role="tab"
                        aria-selected={activeCategoryId === category.id}
                        aria-controls={`panel-${category.id}`}
                        onClick={() => setActiveCategoryId(category.id)}
                        className={`shrink-0 rounded-full border px-4 py-2.5 text-sm font-semibold transition ${activeCategoryId === category.id ? "border-[#a84127] bg-[#a84127] text-white" : "border-[#e7d8ca] bg-white text-[#674d41] hover:border-[#bd795d]"}`}
                    >
                        {category.title}
                    </button>
                ))}
            </div>
            <div id={`panel-${activeCategory.id}`} role="tabpanel" aria-labelledby={`tab-${activeCategory.id}`} className="grid gap-4 sm:grid-cols-2">
                {activeCategory.items.map((item) => (
                    <article key={item.name} className="group rounded-2xl border border-[#ead9c8] bg-white p-5 shadow-[0_8px_24px_rgba(80,47,31,0.04)] transition duration-300 hover:-translate-y-1 hover:border-[#d7b8a2] hover:shadow-[0_16px_32px_rgba(80,47,31,0.09)]">
                        <div className="mb-4 flex items-center justify-between gap-3 border-b border-[#f1e7df] pb-3">
                            <h3 className="font-serif text-xl font-semibold text-[#3d2c25]">{item.name}</h3>
                            <span className="text-xs font-medium text-[#9a8275]">{item.variants.length > 1 ? "Pilih porsi" : "Harga"}</span>

                        </div>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            {item.variants.map((variant) => (
                                <div key={variant.label} className={`min-h-[72px] rounded-xl border px-3 py-2.5 ${variant.recommended ? "border-[#d9a58d] bg-[#fff2e9]" : "border-transparent bg-[#faf6f1]"}`}>
                                    <div className="flex min-h-4 items-center justify-between gap-1">
                                        <span className="text-xs font-medium text-[#806a5f]">{variant.label}</span>
                                        {variant.recommended && <span className="whitespace-nowrap rounded-full bg-[#a84127] px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-white">Rekomendasi</span>}
                                    </div>
                                    <span className="mt-2 block whitespace-nowrap text-sm font-bold tabular-nums text-[#34251f]">{formatPrice(variant.price)}</span>
                                </div>
                            ))}
                        </div>
                    </article>
                ))}
            </div>
        </>
    );
}

export default function Welcome({ business = {}, outlets = [] }) {
    useEffect(() => {
        const elements = document.querySelectorAll(".scroll-reveal");
        const showAll = () => elements.forEach((element) => element.classList.add("is-visible"));

        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches || !("IntersectionObserver" in window)) {
            showAll();
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add("is-visible");
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: "0px 0px -48px 0px" });

        elements.forEach((element) => observer.observe(element));
        return () => observer.disconnect();
    }, []);

    const [report, setReport] = useState({ product: "", branch: "", purchasedAt: "", details: "", agreed: false });
    const phone = contact.phone;
    const email = contact.email;
    const displayBranches = outlets.length ? outlets : branches;

    const sendProductReport = (event) => {
        event.preventDefault();
        const message = [
            "Halo CS Weigu, saya ingin menyampaikan aduan/laporan produk.",
            `Produk: ${report.product}`,
            `Cabang: ${report.branch || "Tidak disebutkan"}`,
            `Waktu pembelian: ${report.purchasedAt || "Tidak disebutkan"}`,
            `Kronologi aduan: ${report.details}`,
        ].join("\n");
        window.open(`https://wa.me/${CS_WHATSAPP}?text=${encodeURIComponent(message)}`, "_blank", "noopener,noreferrer");
    };

    return (
        <>
            <Head title={`${business.name || "Dimsum"} — Dimsum Hangat, Dibuat dengan Hati`}>
                <meta
                    name="description"
                    content={`${business.name || "Dimsum"} menyajikan dimsum hangat dan lezat untuk menemani waktu makan Anda.`}
                />
            </Head>

            <div className="min-h-screen bg-[#fffaf4] text-[#34251f]">
                <header className="absolute inset-x-0 top-0 z-20">
                    <nav className="mx-auto flex max-w-7xl items-center justify-between px-5 py-5 sm:px-8">
                        <a href="#beranda" className="flex items-center gap-3" aria-label={`${business.name} — beranda`}>
                            <img src="/assets/logo/logo-no-background.png" alt="Weigu Dimsum & Gyoza" className="h-12 w-auto max-w-[170px] object-contain sm:h-14" />
                        </a>
                        <div className="hidden items-center gap-8 text-sm font-medium text-[#695850] md:flex">
                            <a href="#cerita" className="transition hover:text-[#a84127]">Cerita Kami</a>
                            <a href="#menu" className="transition hover:text-[#a84127]">Menu</a>
                            <a href="#outlet" className="transition hover:text-[#a84127]">Outlet</a>
                        </div>
                    </nav>
                </header>

                <main>
                    <section id="beranda" className="relative isolate overflow-hidden">
                        <div className="absolute inset-0 -z-10 bg-[#f7ecdf]" />
                        <div className="absolute inset-0 -z-10 bg-cover bg-center md:bg-[position:center_54%]" style={{ backgroundImage: "url('/images/dimsum-hero.png')" }} />
                        <div className="absolute inset-0 -z-10 bg-gradient-to-r from-[#fffaf4]/95 via-[#fffaf4]/80 to-[#fffaf4]/20 md:to-transparent" />
                        <div className="mx-auto flex min-h-[680px] max-w-7xl items-center px-5 pb-20 pt-32 sm:px-8 md:min-h-[760px]">
                            <div className="scroll-reveal max-w-2xl">
                                <p className="mb-6 inline-flex items-center gap-2 rounded-full border border-[#d8bca8] bg-white/70 px-4 py-2 text-xs font-bold uppercase tracking-[0.18em] text-[#9b482d]">
                                    Dimsum hangat, setiap hari
                                </p>
                                <h1 className="font-serif text-5xl font-semibold leading-[1.08] tracking-tight text-[#34251f] sm:text-6xl md:text-7xl">
                                    Momen kecil,
                                    <span className="block text-[#a84127]">rasa yang istimewa.</span>
                                </h1>
                                <p className="mt-6 max-w-xl text-base leading-7 text-[#695850] sm:text-lg sm:leading-8">
                                    {business.description || "Dibuat hangat dengan bahan pilihan dan penuh perhatian. Temukan dimsum favorit untuk dinikmati bersama orang-orang tersayang."}
                                </p>
                                <div className="mt-9 flex flex-col gap-3 sm:flex-row">
                                    <a href="#menu" className="inline-flex items-center justify-center gap-2 rounded-full bg-[#a84127] px-7 py-3.5 font-semibold text-white shadow-lg shadow-[#a84127]/20 transition hover:-translate-y-0.5 hover:bg-[#87351f]">
                                        Lihat menu <IconArrowDown size={18} />
                                    </a>
                                    <a href={GOFOOD_URL} target="_blank" rel="noreferrer" className="inline-flex items-center justify-center gap-2 rounded-full border border-[#b99c8b] bg-white/80 px-7 py-3.5 font-semibold text-[#49372f] transition hover:bg-white">Pesan lewat GoFood <IconArrowUpRight size={17} /></a>
                                </div>
                                <div className="mt-12 flex items-center gap-3 text-sm text-[#695850]">
                                    <span className="flex -space-x-2" aria-hidden="true">
                                        {["bg-[#d99765]", "bg-[#e9bd8a]", "bg-[#b97858]"].map((color) => <span key={color} className={`h-8 w-8 rounded-full border-2 border-[#fffaf4] ${color}`} />)}
                                    </span>
                                    <span>Diracik segar, disajikan dengan hangat</span>
                                </div>
                            </div>
                        </div>
                        <a href="#cerita" aria-label="Lanjut ke cerita kami" className="absolute bottom-7 left-1/2 hidden -translate-x-1/2 items-center gap-2 text-xs font-semibold uppercase tracking-widest text-[#695850] md:flex">
                            Jelajahi <IconArrowDown size={15} />
                        </a>
                    </section>

                    <section id="cerita" className="mx-auto grid max-w-7xl gap-12 px-5 py-24 sm:px-8 md:grid-cols-[0.8fr_1.2fr] md:items-center md:py-32">
                        <div className="scroll-reveal">
                            <p className="text-xs font-bold uppercase tracking-[0.2em] text-[#a84127]">Dari dapur kami</p>
                            <h2 className="mt-4 font-serif text-4xl font-semibold leading-tight sm:text-5xl">Rasa rumahan, dibuat sepenuh hati.</h2>
                        </div>
                        <div className="scroll-reveal space-y-5 text-base leading-8 text-[#695850]" style={{ "--reveal-delay": "120ms" }}>
                            <p>{business.description || `${business.name || "Kami"} percaya hidangan yang baik dimulai dari bahan yang baik dan dibuat dengan penuh perhatian. Setiap sajian kami siapkan agar terasa hangat, nyaman, dan selalu ingin dinikmati lagi.`}</p>
                            <p>Kami menyambut Anda untuk menikmati dimsum bersama keluarga, teman, atau sebagai teman istirahat di tengah kesibukan.</p>
                            <a href="#menu" className="inline-flex items-center gap-2 font-semibold text-[#a84127] hover:text-[#87351f]">Kenali menu kami <IconArrowUpRight size={18} /></a>
                        </div>
                    </section>

                    <section id="menu" className="bg-[#f7eee5] px-5 py-20 sm:px-8 md:py-24">
                        <div className="mx-auto max-w-5xl">
                            <div className="scroll-reveal mb-8">
                                <p className="text-xs font-bold uppercase tracking-[0.2em] text-[#a84127]">Menu</p>
                                <div className="mt-2 flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                                    <div><h2 className="font-serif text-4xl font-semibold sm:text-5xl">Dimsum, lumpia &amp; lainnya.</h2><p className="mt-3 text-sm text-[#75645c]">Pilih kategori untuk melihat menu dan harga.</p></div>
                                    <a href={GOFOOD_URL} target="_blank" rel="noreferrer" className="inline-flex w-fit items-center gap-2 rounded-full bg-[#a84127] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#87351f]">Pesan lewat GoFood <IconArrowUpRight size={17} /></a>
                                </div>
                            </div>
                            <MenuPriceList />
                        </div>
                    </section>

                    <section id="outlet" className="border-t border-[#ead9c8] bg-white px-5 py-24 sm:px-8 md:py-28">
                        <div className="mx-auto max-w-7xl">
                            <div className="scroll-reveal mb-8 max-w-xl"><p className="text-xs font-bold uppercase tracking-[0.2em] text-[#a84127]">Kami menanti Anda</p><h2 className="mt-3 font-serif text-4xl font-semibold sm:text-5xl">Mampir atau sapa kami.</h2><p className="mt-3 text-sm leading-6 text-[#75645c]">Pilih cabang terdekat dan cek jam buka kami.</p></div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {displayBranches.map((branch) => (
                                    <details key={branch.id || branch.name} className="group rounded-2xl border border-[#f0e4d8] bg-[#fffaf4] shadow-[0_6px_20px_rgba(80,47,31,0.04)] transition hover:border-[#dfc5b2] hover:shadow-[0_12px_28px_rgba(80,47,31,0.08)]">
                                        <summary className="flex cursor-pointer list-none items-center gap-3 p-4 marker:hidden [&::-webkit-details-marker]:hidden">
                                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#f4e4d5] text-[#a84127]"><IconMapPin size={19} /></span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block font-serif text-base font-semibold leading-5">{branch.name}</span>
                                                <span className="mt-1 block truncate text-xs text-[#75645c]">{branch.address}</span>
                                            </span>
                                            <IconArrowDown size={18} className="shrink-0 text-[#a84127] transition-transform group-open:rotate-180" aria-hidden="true" />
                                        </summary>
                                        <div className="space-y-3 border-t border-[#ead9c8] px-4 pb-4 pt-3">
                                            <a href={branch.map} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 rounded-full border border-[#d9c5b7] px-3 py-1.5 text-xs font-semibold text-[#8f3825] transition hover:border-[#a84127] hover:bg-white" aria-label={`Buka peta ${branch.name}`}>
                                                Lihat peta <IconArrowUpRight size={14} />
                                            </a>
                                            <div className="space-y-2">
                                                {branch.hours.map((schedule) => (
                                                    <div key={schedule.days} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 text-sm">
                                                        <span className="flex items-center gap-2 text-[#75645c]"><IconClock size={15} className="shrink-0" />{schedule.days}</span>
                                                        <span className="font-semibold tabular-nums text-[#49372f]">{schedule.time} WIB</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </details>
                                ))}
                            </div>
                            <div className="scroll-reveal mt-6 flex flex-col justify-between gap-6 rounded-3xl bg-[#34251f] p-7 text-white sm:flex-row sm:items-center sm:p-9">
                                <div className="flex items-start gap-4">
                                    <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/10 text-[#f0c3a7]"><IconPhone size={23} /></span>
                                    <div><p className="text-xs font-bold uppercase tracking-[0.18em] text-[#e9b99f]">Kontak</p><h3 className="mt-1 font-serif text-2xl font-semibold">Kami siap menyapa.</h3><div className="mt-3 flex flex-col gap-1 text-sm text-white/75 sm:flex-row sm:gap-5"><a href={`tel:${phone}`} className="transition hover:text-white">{phone}</a><a href={`mailto:${email}`} className="transition hover:text-white">{email}</a></div></div>
                                </div>
                                <div className="w-full space-y-3 sm:max-w-xl">
                                    <a href={`https://wa.me/${CS_WHATSAPP}`} target="_blank" rel="noreferrer" className="inline-flex items-center justify-center rounded-full bg-[#a84127] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#87351f]">Hubungi CS via WhatsApp</a>
                                    <details className="rounded-2xl border border-white/15 bg-white/5 p-4">
                                        <summary className="cursor-pointer list-none font-semibold text-white marker:hidden [&::-webkit-details-marker]:hidden">Laporkan produk ke CS</summary>
                                        <div className="mt-4 rounded-xl bg-[#fffaf4] p-4 text-[#34251f] sm:p-5">
                                            <p className="text-sm leading-6 text-[#695850]">Isi detail laporan. WhatsApp akan terbuka dengan pesan yang bisa Anda tinjau sebelum dikirim.</p>
                                            <form onSubmit={sendProductReport} className="mt-4 space-y-3">
                                                <label className="block text-sm font-semibold">Produk yang dilaporkan
                                                    <select required value={report.product} onChange={(event) => setReport((current) => ({ ...current, product: event.target.value }))} className="mt-1.5 h-11 w-full rounded-xl border border-[#e2d3c6] bg-white px-3 font-normal text-[#34251f] focus:border-[#a84127] focus:outline-none focus:ring-2 focus:ring-[#a84127]/15">
                                                        <option value="">Pilih produk</option>
                                                        {menuCategories.flatMap((category) => category.items.map((item) => <option key={item.name} value={item.name}>{item.name}</option>))}
                                                    </select>
                                                </label>
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    <label className="block text-sm font-semibold">Cabang <span className="font-normal text-[#9a8275]">(opsional)</span>
                                                        <select value={report.branch} onChange={(event) => setReport((current) => ({ ...current, branch: event.target.value }))} className="mt-1.5 h-11 w-full rounded-xl border border-[#e2d3c6] bg-white px-3 font-normal text-[#34251f] focus:border-[#a84127] focus:outline-none focus:ring-2 focus:ring-[#a84127]/15">
                                                            <option value="">Pilih cabang</option>
                                                            {displayBranches.map((branch) => <option key={branch.id || branch.name} value={branch.name}>{branch.name}</option>)}
                                                        </select>
                                                    </label>
                                                    <label className="block text-sm font-semibold">Waktu pembelian <span className="font-normal text-[#9a8275]">(opsional)</span>
                                                        <input type="date" value={report.purchasedAt} onChange={(event) => setReport((current) => ({ ...current, purchasedAt: event.target.value }))} className="mt-1.5 h-11 w-full rounded-xl border border-[#e2d3c6] bg-white px-3 font-normal text-[#34251f] focus:border-[#a84127] focus:outline-none focus:ring-2 focus:ring-[#a84127]/15" />
                                                    </label>
                                                </div>
                                                <label className="block text-sm font-semibold">Ceritakan kendala atau aduan
                                                    <textarea required minLength={10} maxLength={1000} rows={4} value={report.details} onChange={(event) => setReport((current) => ({ ...current, details: event.target.value }))} placeholder="Tuliskan kondisi produk dan kronologi singkat…" className="mt-1.5 w-full resize-y rounded-xl border border-[#e2d3c6] bg-white px-3 py-2.5 font-normal text-[#34251f] placeholder:text-[#a89589] focus:border-[#a84127] focus:outline-none focus:ring-2 focus:ring-[#a84127]/15" />
                                                </label>
                                                <label className="flex items-start gap-2.5 text-xs leading-5 text-[#695850]">
                                                    <input required type="checkbox" checked={report.agreed} onChange={(event) => setReport((current) => ({ ...current, agreed: event.target.checked }))} className="mt-1 h-4 w-4 shrink-0 accent-[#a84127]" />
                                                    <span>Saya memastikan laporan ini benar dan terkait produk Weigu. Saya paham pesan baru terkirim setelah saya meninjaunya lalu menekan tombol kirim di WhatsApp. Saya tidak mencantumkan kata sandi, data pembayaran, atau informasi sensitif.</span>
                                                </label>
                                                <button type="submit" className="inline-flex w-full items-center justify-center rounded-full bg-[#a84127] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#87351f] sm:w-auto">Buka WhatsApp &amp; Tinjau Pesan</button>
                                            </form>
                                        </div>
                                    </details>
                                </div>
                            </div>
                        </div>
                    </section>

                </main>

                <footer className="border-t border-white/10 bg-[#2e211c] px-5 py-10 text-white sm:px-8">
                    <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-6 text-center sm:flex-row sm:text-left">
                        <div className="flex items-center gap-4"><img src="/assets/logo/logo-square.png" alt="Logo Weigu Dimsum & Gyoza" className="h-14 w-14 rounded-2xl object-cover shadow-lg shadow-black/20" /><div><p className="font-serif text-xl font-semibold">{business.name || "Weigu Dimsum & Gyoza"}</p><p className="mt-1 text-sm text-white/60">Dimsum hangat, dinikmati bersama.</p></div></div>
                        <nav aria-label="Navigasi footer" className="flex flex-wrap justify-center gap-x-5 gap-y-3 text-sm text-white/70">
                            <a href="#menu" className="transition hover:text-white">Lihat menu</a>
                            <a href="#outlet" className="transition hover:text-white">Lokasi cabang</a>
                            <a href={`https://wa.me/${CS_WHATSAPP}`} target="_blank" rel="noreferrer" className="transition hover:text-white">WhatsApp CS</a>
                            <a href={`mailto:${email}`} className="transition hover:text-white">Email</a>
                        </nav>
                        <p className="text-xs text-white/50">© {new Date().getFullYear()} {business.name || "Dimsum"}</p>
                    </div>
                </footer>
            </div>
        </>
    );
}
