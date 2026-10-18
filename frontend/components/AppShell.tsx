"use client";

import Link from "next/link";
import { useT } from "@/lib/i18n";
import { usePathname } from "next/navigation";
import { useState } from "react";
import { BadgeDollarSign, CalendarDays, HandCoins, Menu, X, Building2, Car, ChartColumn, KeyRound, LayoutDashboard, Landmark, LogOut, Settings, ShieldCheck, ShoppingCart, Tags, Truck, UserCog, Users, Wallet } from "lucide-react";
import { useAuth } from "@/lib/auth";

type Item = { href: string; text: string; icon: typeof Car; color: string; permission?: string; roles?: string[] };

const NAV: Item[] = [
  { href: "/shops", text: "Shops", icon: Building2, color: "text-emerald-600", roles: ["super_admin"] },
  { href: "/categories", text: "Categories", icon: Tags, color: "text-sky-600", roles: ["super_admin"] },
  { href: "/permissions", text: "Permissions", icon: KeyRound, color: "text-amber-500", roles: ["super_admin"] },
  { href: "/", text: "Dashboard", icon: LayoutDashboard, color: "text-emerald-600", permission: "dashboard.view" },
  { href: "/sales/new", text: "Sales", icon: BadgeDollarSign, color: "text-green-600", permission: "sales.create" },
  { href: "/purchases/new", text: "Purchases", icon: ShoppingCart, color: "text-orange-500", permission: "purchases.create" },
  { href: "/vehicles", text: "Vehicles", icon: Car, color: "text-blue-600", permission: "vehicles.view" },
  { href: "/due-collection", text: "Due collection", icon: HandCoins, color: "text-green-600", permission: "payments.collect" },
  { href: "/collections", text: "Due alert", icon: Wallet, color: "text-amber-500", permission: "collections.view" },
  { href: "/supplier-payment", text: "Supplier payment", icon: HandCoins, color: "text-green-600", permission: "purchases.edit" },
  { href: "/customers", text: "Customers", icon: Users, color: "text-sky-600", permission: "parties.view" },
  { href: "/suppliers", text: "Suppliers", icon: Truck, color: "text-orange-500", permission: "parties.view" },
  { href: "/investors", text: "Investors", icon: Landmark, color: "text-violet-600", permission: "investors.view" },
  { href: "/daily-report", text: "Daily report", icon: CalendarDays, color: "text-sky-600", permission: "reports.view" },
  { href: "/reports", text: "Reports", icon: ChartColumn, color: "text-slate-600", permission: "reports.view" },
  { href: "/settings", text: "Settings", icon: Settings, color: "text-slate-600", permission: "settings.manage" },
  { href: "/users", text: "Users", icon: UserCog, color: "text-sky-600", roles: ["shop_owner"] },
  { href: "/roles", text: "Roles", icon: ShieldCheck, color: "text-emerald-600", roles: ["shop_owner"] },
];

export function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { user, logout, can } = useAuth();

  const [menuOpen, setMenuOpen] = useState(false); // phone only: the menu folds away so the page gets the full screen

  // going to another page folds the phone menu away again
  const [menuPath, setMenuPath] = useState(pathname);
  if (menuPath !== pathname) {
    setMenuPath(pathname);
    setMenuOpen(false);
  }

  const { t, lang, setLang } = useT();
  const items = NAV.filter((i) => (i.roles ? i.roles.includes(user?.role ?? "") : i.permission ? can(i.permission) : true));

  return (
    <div className="min-h-screen overflow-x-hidden md:flex print:block">
      <aside className="print:hidden bg-gradient-to-b from-emerald-800 via-green-700 to-emerald-700 text-white md:sticky md:top-0 md:flex md:h-screen md:w-60 md:shrink-0 md:flex-col md:overflow-hidden">
        <div className="flex items-center gap-2 px-5 py-3 md:py-5">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-white/20"><Car className="size-5" /></span>
          <div className="min-w-0 flex-1">
            <p className="text-lg font-bold leading-tight">{t("Vehicle Hub")}</p>
            {user?.shop && <p className="truncate text-xs text-white/75">{user.shop.name}</p>}
          </div>
          <button type="button" aria-label={menuOpen ? "Close menu" : "Open menu"} aria-expanded={menuOpen} className="flex size-10 items-center justify-center rounded-xl bg-white/15 hover:bg-white/25 md:hidden" onClick={() => setMenuOpen((o) => !o)}>
            {menuOpen ? <X className="size-5" /> : <Menu className="size-5" />}
          </button>
        </div>
        <nav className={`${menuOpen ? "grid" : "hidden"} grid-cols-2 gap-1 px-3 pb-3 sm:grid-cols-3 md:flex md:min-h-0 md:flex-1 md:flex-col md:overflow-y-auto md:pb-2`}>
          {items.map(({ href, text, icon: Icon, color }) => {
            const active = href === "/" ? pathname === "/" : pathname.startsWith(href);
            return (
              <Link
                key={href}
                href={href}
                className={`flex items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-medium transition md:gap-3 md:px-3 md:py-2.5 ${active ? "bg-white text-emerald-700 shadow" : "text-white/85 hover:bg-white/15"}`}
              >
                <span className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm"><Icon className={`size-4 ${color}`} /></span>
                {t(text)}
              </Link>
            );
          })}
        </nav>
        <div className={`${menuOpen ? "block" : "hidden"} shrink-0 border-t border-white/15 p-3 md:block`}>
          <div className="mb-2 flex items-center justify-between px-1 text-xs text-white/80"><span>{t("Language")}</span><div className="flex overflow-hidden rounded-lg bg-white/15 text-xs font-semibold" role="group" aria-label="Language">
              <button type="button" onClick={() => setLang("en")} className={`px-2 py-1.5 ${lang === "en" ? "bg-white text-emerald-700" : "text-white/85 hover:bg-white/15"}`}>EN</button>
              <button type="button" onClick={() => setLang("bn")} className={`px-2 py-1.5 ${lang === "bn" ? "bg-white text-emerald-700" : "text-white/85 hover:bg-white/15"}`}>বাং</button>
            </div></div>
          <div className="flex items-center gap-2.5 rounded-xl bg-white/10 p-2.5">
            <Link href="/profile" title="Profile settings" className="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg hover:bg-white/10">
              <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-white text-sm font-bold text-emerald-700">{user?.name?.[0]?.toUpperCase()}</span>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold leading-tight">{user?.name}</p>
                <p className="truncate text-xs text-white/75">{user?.role_label}</p>
              </div>
            </Link>
            <button type="button" aria-label="Logout" title="Logout" onClick={logout} className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/15 hover:bg-white/25"><LogOut className="size-4" /></button>
          </div>
        </div>
      </aside>
      <div className="min-w-0 flex-1">
        <main className="min-w-0 p-4 md:p-6 print:p-0">{children}</main>
      </div>
    </div>
  );
}
