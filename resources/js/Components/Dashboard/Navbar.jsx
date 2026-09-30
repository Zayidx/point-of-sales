import React from "react";
import { usePage } from "@inertiajs/react";
import {
    IconMenu2,
    IconMoon,
    IconSun,
    IconQuestionMark,
} from "@tabler/icons-react";
import AuthDropdown from "@/Components/Dashboard/AuthDropdown";
import OutletSwitcher from "@/Components/Dashboard/OutletSwitcher";
import Menu from "@/Utils/Menu";
import Notification from "@/Components/Dashboard/Notification";
import { useTour } from "@/Hooks/useTour";
import i18n from "@/i18n";

export default function Navbar({ toggleSidebar, themeSwitcher, darkMode }) {
    const { auth, storeProfile } = usePage().props;
    const { start: startTour, isActive: tourActive } = useTour("dashboard");
    const menuNavigation = Menu();

    const storeName = storeProfile?.name || "KASIR";
    const storeInitial = storeName?.charAt(0)?.toUpperCase() || "K";

    // Get current page title
    const links = menuNavigation.flatMap((item) => item.details);
    const sublinks = links
        .filter((item) => item.hasOwnProperty("subdetails"))
        .flatMap((item) => item.subdetails);

    const getCurrentTitle = () => {
        for (const link of links) {
            if (link.hasOwnProperty("subdetails")) {
                const activeSublink = sublinks.find((s) => s.active);
                if (activeSublink) return activeSublink.title;
            } else if (link.active) {
                return link.title;
            }
        }
        return "Beranda";
    };

    return (
        <header
            className="sticky top-0 z-30 flex h-16 items-center justify-between px-2 sm:px-4 md:px-6
            bg-white dark:bg-slate-900
            border-b border-slate-200 dark:border-slate-800
            transition-colors duration-200"
        >
            {/* Left Section */}
            <div className="flex min-w-0 items-center gap-2 sm:gap-4">
                {/* Sidebar Toggle */}
                <button
                    onClick={toggleSidebar}
                    className="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                    title="Tampilkan atau sembunyikan menu"
                >
                    <IconMenu2 size={20} strokeWidth={1.5} />
                </button>

                {/* Mobile Logo */}
                <div className="md:hidden flex items-center gap-2">
                    <div className="w-7 h-7 rounded-lg bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center">
                        <span className="text-white font-bold text-xs">{storeInitial}</span>
                    </div>
                    <span className="text-lg font-bold text-slate-800 dark:text-white">
                        {storeName}
                    </span>
                </div>

                {/* Current Page Title */}
                <div className="hidden md:flex items-center">
                    <div className="w-px h-6 bg-slate-200 dark:bg-slate-700 mr-4" />
                    <h1 className="text-base font-semibold text-slate-800 dark:text-slate-200">
                        {getCurrentTitle()}
                    </h1>
                </div>
            </div>

            {/* Right Section */}
            <div className="flex shrink-0 items-center gap-0.5 sm:gap-2">
                <OutletSwitcher
                    outlet={auth?.currentOutlet}
                    outlets={auth?.outlets}
                    locked={auth?.outletLocked}
                    isFinance={auth?.isFinance}
                    centralWarehouse={auth?.centralWarehouse}
                />
                {/* Tour Guide */}
                <button
                    onClick={startTour}
                    disabled={tourActive}
                    className="hidden min-h-11 min-w-11 items-center justify-center rounded-xl text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200 sm:flex"
                    title={i18n.t("tour.button")}
                >
                    <IconQuestionMark size={20} strokeWidth={1.5} />
                </button>

                {/* Theme Toggle */}
                <button
                    onClick={themeSwitcher}
                    className="hidden min-h-11 min-w-11 items-center justify-center rounded-xl text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200 sm:flex"
                    title={darkMode ? "Mode terang" : "Mode gelap"}
                >
                    {darkMode ? (
                        <IconSun
                            size={20}
                            strokeWidth={1.5}
                            className="text-amber-500"
                        />
                    ) : (
                        <IconMoon size={20} strokeWidth={1.5} />
                    )}
                </button>

                {/* Notifications */}
                <Notification />

                {/* Divider */}
                <div className="w-px h-8 bg-slate-200 dark:bg-slate-700 mx-1" />

                {/* User Dropdown */}
                <AuthDropdown auth={auth} />
            </div>
        </header>
    );
}
