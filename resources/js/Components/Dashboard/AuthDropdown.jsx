import { Menu, Transition } from "@headlessui/react";
import { router, useForm } from "@inertiajs/react";
import axios from "axios";
import { IconLogout, IconRotate } from "@tabler/icons-react";
import i18n from "@/i18n";

export default function AuthDropdown({ auth }) {
    const { post } = useForm();
    const avatarUrl = auth.user.avatar;
    const userInitial = auth.user.name?.charAt(0)?.toUpperCase() ?? auth.user.email?.charAt(0)?.toUpperCase() ?? "?";

    const logout = (event) => {
        event.preventDefault();
        post(route("logout"));
    };

    const resetTours = (event) => {
        event.preventDefault();
        axios
            .post(route("tours.reset"), {}, { headers: { Accept: "application/json" } })
            .then(() => router.reload({ only: ["auth"] }));
    };

    return (
        <Menu className="relative z-40" as="div">
            <Menu.Button
                className="flex min-h-11 min-w-11 items-center justify-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                aria-label={`Menu akun ${auth.user.name}`}
            >
                {avatarUrl ? (
                    <img src={avatarUrl} alt="" className="h-10 w-10 rounded-full object-cover" />
                ) : (
                    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-primary-100 font-semibold text-primary-700">
                        {userInitial}
                    </span>
                )}
            </Menu.Button>
            <Transition
                enter="transition duration-100 ease-out"
                enterFrom="scale-95 opacity-0"
                enterTo="scale-100 opacity-100"
                leave="transition duration-75 ease-in"
                leaveFrom="scale-100 opacity-100"
                leaveTo="scale-95 opacity-0"
            >
                <Menu.Items className="absolute right-0 mt-2 w-56 origin-top-right rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl focus:outline-none dark:border-slate-700 dark:bg-slate-900">
                    <p className="truncate px-3 py-2 text-xs text-slate-500 dark:text-slate-400">{auth.user.name}</p>
                    <Menu.Item>
                        {({ active }) => (
                            <button onClick={resetTours} className={`flex min-h-11 w-full items-center gap-2 rounded-lg px-3 text-left text-sm ${active ? "bg-slate-100 dark:bg-slate-800" : ""}`}>
                                <IconRotate size={18} />
                                {i18n.t("tour.reset")}
                            </button>
                        )}
                    </Menu.Item>
                    <Menu.Item>
                        {({ active }) => (
                            <button onClick={logout} className={`flex min-h-11 w-full items-center gap-2 rounded-lg px-3 text-left text-sm text-rose-600 ${active ? "bg-rose-50 dark:bg-rose-950/30" : ""}`}>
                                <IconLogout size={18} />
                                Keluar
                            </button>
                        )}
                    </Menu.Item>
                </Menu.Items>
            </Transition>
        </Menu>
    );
}
