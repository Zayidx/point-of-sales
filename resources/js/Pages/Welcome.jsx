import { useEffect, useState } from "react";
import { Head } from "@inertiajs/react";
import {
    IconArrowDown,
    IconArrowUpRight,
    IconClock,
    IconMapPin,
    IconPhone,
    IconSoup,
} from "@tabler/icons-react";

const formatPrice = (price) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(price || 0);

const menuCategories = [
    {
        id: "dimsum-kukus", title: "Dimsum Kukus",
        items: [
            { name: "Dimsum Ori", variants: [{ label: "4 pcs", price: 16000 }, { label: "6 pcs", price: 24000 }, { label: "8 pcs", price: 30000 }] },
            { name: "Dimsum Mentai", variants: [{ label: "4 pcs", price: 21000 }, { label: "6 pcs", price: 28000 }, { label: "8 pcs", price: 38000 }] },
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
            { name: "Chiquro Mentai", variants: [{ label: "Per porsi", price: 26000 }] },
            { name: "Chiquro Cheese", variants: [{ label: "Per porsi", price: 28000 }] },
        ],
    },
    {
        id: "paket-besar", title: "Paket Besar",
        items: [
            { name: "Big Package Dimsum Mix", variants: [{ label: "Paket", price: 90000 }] },
            { name: "Big Package Dimsum Mentai", variants: [{ label: "Paket", price: 85000 }] },
            { name: "Big Package Dimsum Cheesemelt", variants: [{ label: "Paket", price: 100000 }] },
        ],
    },
];

const contact = {
    phone: "083129701342",
    email: "faridindrawan@gmail.com",
};
const GOFOOD_URL = "https://gofood.co.id/";

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
            <div id={`panel-${activeCategory.id}`} role="tabpanel" aria-labelledby={`tab-${activeCategory.id}`} className="divide-y divide-[#eee3d9] border-y border-[#e8d9cb] bg-white/70 px-4 sm:px-6">
                {activeCategory.items.map((item) => (
                    <article key={item.name} className="grid gap-3 py-5 sm:grid-cols-[minmax(155px,0.8fr)_1.6fr] sm:items-center sm:gap-6">
                        <h3 className="font-semibold text-[#3d2c25]">{item.name}</h3>
                        <div className="grid grid-cols-2 gap-x-3 gap-y-2 sm:grid-cols-3 sm:gap-x-5">
                            {item.variants.map((variant) => (
                                <div key={variant.label} className="flex min-h-14 flex-col items-start justify-center gap-1 rounded-lg bg-[#fffaf5] px-3 py-2 sm:bg-transparent sm:px-2">
                                    <span className="whitespace-nowrap text-xs leading-tight tracking-normal text-[#806a5f]">{variant.label}</span>
                                    <span className="whitespace-nowrap text-sm font-semibold leading-tight tracking-normal tabular-nums text-[#34251f]">{formatPrice(variant.price)}</span>
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

    const phone = business.phone || contact.phone;
    const email = business.email || contact.email;
    const displayBranches = outlets.length ? outlets : branches;

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
                            {business.logo ? (
                                <img src={business.logo} alt="" className="h-11 w-11 rounded-full object-cover" />
                            ) : (
                                <span className="flex h-11 w-11 items-center justify-center rounded-full bg-[#a84127] text-white">
                                    <IconSoup size={24} />
                                </span>
                            )}
                            <span className="font-serif text-lg font-bold tracking-tight sm:text-xl">
                                {business.name || "Dimsum"}
                            </span>
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
                            <div className="scroll-reveal mb-12 max-w-xl"><p className="text-xs font-bold uppercase tracking-[0.2em] text-[#a84127]">Kami menanti Anda</p><h2 className="mt-3 font-serif text-4xl font-semibold sm:text-5xl">Mampir atau sapa kami.</h2><p className="mt-4 text-base leading-7 text-[#75645c]">Pilih cabang terdekat, cek jam buka, lalu mampir untuk menikmati dimsum hangat.</p></div>
                            <div className="grid gap-5 sm:grid-cols-2">
                                {displayBranches.map((branch, index) => (
                                    <article key={branch.id || branch.name} className="scroll-reveal rounded-3xl border border-[#f0e4d8] bg-[#fffaf4] p-7 shadow-[0_8px_30px_rgba(80,47,31,0.05)] transition duration-300 hover:-translate-y-1 hover:shadow-[0_18px_40px_rgba(80,47,31,0.1)] sm:p-8" style={{ "--reveal-delay": `${index * 90}ms` }}>
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#f4e4d5] text-[#a84127]"><IconMapPin size={22} /></span>
                                                <h3 className="mt-5 font-serif text-xl font-semibold">{branch.name}</h3>
                                                <p className="mt-2 text-sm leading-6 text-[#75645c]">{branch.address}</p>
                                            </div>
                                            <a href={branch.map} target="_blank" rel="noreferrer" className="inline-flex shrink-0 items-center gap-1 rounded-full border border-[#d9c5b7] px-3.5 py-2.5 text-xs font-semibold text-[#8f3825] transition hover:border-[#a84127] hover:bg-white" aria-label={`Buka peta ${branch.name}`}>
                                                Peta <IconArrowUpRight size={15} />
                                            </a>
                                        </div>
                                        <div className="mt-6 border-t border-[#ead9c8] pt-5">
                                            <p className="mb-3 flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-[#9b482d]"><IconClock size={16} /> Jam operasional</p>
                                            {branch.hours.map((schedule) => (
                                                <div key={schedule.days} className="flex justify-between gap-4 py-1.5 text-sm">
                                                    <span className="text-[#75645c]">{schedule.days}</span>
                                                    <span className="font-semibold tabular-nums text-[#49372f]">{schedule.time} WIB</span>
                                                </div>
                                            ))}
                                        </div>
                                    </article>
                                ))}
                            </div>
                            <div className="scroll-reveal mt-6 flex flex-col justify-between gap-6 rounded-3xl bg-[#34251f] p-7 text-white sm:flex-row sm:items-center sm:p-9">
                                <div className="flex items-start gap-4">
                                    <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/10 text-[#f0c3a7]"><IconPhone size={23} /></span>
                                    <div><p className="text-xs font-bold uppercase tracking-[0.18em] text-[#e9b99f]">Kontak</p><h3 className="mt-1 font-serif text-2xl font-semibold">Kami siap menyapa.</h3><div className="mt-3 flex flex-col gap-1 text-sm text-white/75 sm:flex-row sm:gap-5"><a href={`tel:${phone}`} className="transition hover:text-white">{phone}</a><a href={`mailto:${email}`} className="transition hover:text-white">{email}</a></div></div>
                                </div>
                            </div>
                        </div>
                    </section>

                </main>

                <footer className="bg-[#34251f] px-5 py-8 text-white sm:px-8">
                    <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 text-center sm:flex-row sm:text-left">
                        <div><p className="font-serif text-lg font-semibold">{business.name || "Dimsum"}</p><p className="mt-1 text-xs text-white/60">Dibuat hangat, dinikmati bersama.</p></div>
                        <p className="text-xs text-white/50">© {new Date().getFullYear()} {business.name || "Dimsum"}</p>
                    </div>
                </footer>
            </div>
        </>
    );
}
