import type { Metadata } from "next";
import Link from "next/link";
import { BadgeDollarSign, CalendarClock, Car, ChartColumn, ClipboardCheck, HandCoins, Languages, Lock, Smartphone, Ticket, Truck, UsersRound, type LucideIcon } from "lucide-react";
import { LandingActions, LandingContact, LandingInstall, LandingIosHint, LandingLanguage, LandingWhatsApp } from "@/components/LandingParts";
import { VehicleScene } from "@/components/LoginPoster";

export const metadata: Metadata = {
  title: "Gari Becho · Vehicle shop management",
  description: "Gari Becho (গাড়ি বেচো): manage your vehicle shop's stock, purchases, sales, installments, due collection, investors and reports in one place, in English and Bangla.",
};

const FEATURES: { icon: LucideIcon; title: string; text: string }[] = [
  { icon: Truck, title: "Stock and purchases", text: "Record each purchase and see every vehicle in stock, with its chassis and engine number." },
  { icon: BadgeDollarSign, title: "Sales", text: "Sell for cash or on installments, and print the sale memo on the spot." },
  { icon: Ticket, title: "Bookings", text: "Hold a vehicle for a buyer with an advance, and keep an eye on the deadline." },
  { icon: CalendarClock, title: "Installments and dues", text: "Follow every installment and promise to pay, and see who to call today." },
  { icon: HandCoins, title: "Investors", text: "Record who funded each vehicle and settle their profit when it sells." },
  { icon: ClipboardCheck, title: "Registration", text: "Follow each vehicle's registration, from bank chalan to documents handed over." },
  { icon: ChartColumn, title: "Reports", text: "See daily, stock, sales, purchase and due reports, ready to print or save as PDF." },
  { icon: UsersRound, title: "Your team", text: "Give your staff their own sign-in and choose what each role can do." },
];

const HIGHLIGHTS: { icon: LucideIcon; text: string }[] = [
  { icon: Languages, text: "In English and Bangla" },
  { icon: Smartphone, text: "On phone and computer" },
  { icon: Lock, text: "Your records stay private to your shop" },
];

const STEPS = [
  { title: "Buy", text: "Record the purchase from a supplier. The vehicle joins your stock." },
  { title: "Sell", text: "Choose the vehicle and the buyer, then take cash or set up installments." },
  { title: "Collect", text: "Take payments as they fall due. The balance updates by itself." },
  { title: "Settle", text: "Pay your investors their share and see the figures in your reports." },
];

const SECTION = "mx-auto w-full max-w-6xl scroll-mt-[calc(5rem+env(safe-area-inset-top))] px-4 py-14 sm:py-20";
const NAV_LINK = "text-sm font-medium text-slate-600 hover:text-emerald-700";

function SectionHeading({ title, text }: { title: string; text: string }) {
  return (
    <div className="mx-auto mb-10 max-w-2xl text-center">
      <h2 className="text-2xl font-bold text-slate-800 sm:text-3xl">{title}</h2>
      <p className="mt-2 text-slate-600">{text}</p>
    </div>
  );
}

/** The public front door: rendered on the server so visitors and search engines get the content at once. */
export default function LandingPage() {
  return (
    <div className="flex min-h-screen flex-col bg-white">
      <header className="sticky top-0 z-30 border-b border-emerald-100 bg-white/95 pt-[env(safe-area-inset-top)] backdrop-blur">
        <div className="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-2 px-4">
          <Link href="/" aria-label="Gari Becho" className="flex min-w-0 items-center gap-2 font-bold text-slate-800">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-600 to-green-500 text-white"><Car className="size-5" /></span>
            <span className="hidden truncate sm:inline">Gari Becho</span>
          </Link>
          <nav aria-label="Page sections" className="hidden items-center gap-6 lg:flex">
            <a className={NAV_LINK} href="#features">Features</a>
            <a className={NAV_LINK} href="#how-it-works">How it works</a>
            <a className={NAV_LINK} href="#contact">Contact</a>
          </nav>
          <div className="flex items-center gap-2">
            <LandingInstall />
            <LandingLanguage />
            <LandingActions />
          </div>
        </div>
      </header>

      <LandingIosHint />

      <main className="flex-1">
        <section className="relative overflow-hidden bg-gradient-to-b from-emerald-50 to-white">
          <div className="pointer-events-none absolute -right-16 -top-16 size-72 rounded-full bg-emerald-200/40" />
          <div className="relative mx-auto grid w-full max-w-6xl items-center gap-10 px-4 py-14 sm:py-20 lg:grid-cols-2">
            <div>
              <p className="mb-3 text-sm font-semibold text-emerald-700">Vehicle shop management</p>
              <h1 className="text-4xl font-bold leading-tight text-slate-800 sm:text-5xl">Run your vehicle shop from one place</h1>
              <p className="mt-4 text-lg text-slate-600">Gari Becho keeps your stock, purchases, sales, installments, dues and investors together, so you always know what you own, what you sold and what you are owed.</p>
              <div className="mt-8 flex flex-wrap gap-3">
                <LandingActions large />
              </div>
            </div>
            <VehicleScene className="mx-auto w-full max-w-xl" />
          </div>
        </section>

        <section aria-label="Highlights" className="border-y border-emerald-100 bg-emerald-50/50">
          <ul className="mx-auto grid w-full max-w-6xl gap-3 px-4 py-6 sm:grid-cols-3">
            {HIGHLIGHTS.map(({ icon: Icon, text }) => (
              <li key={text} className="flex items-center justify-center gap-2 text-sm font-medium text-slate-700"><Icon className="size-5 shrink-0 text-emerald-700" /> <span>{text}</span></li>
            ))}
          </ul>
        </section>

        <section id="features" className={SECTION}>
          <SectionHeading title="Everything your shop keeps track of" text="From the day you buy a vehicle to the day the last installment comes in." />
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {FEATURES.map(({ icon: Icon, title, text }) => (
              <article key={title} className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-emerald-200">
                <span className="mb-3 flex size-10 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700"><Icon className="size-5" /></span>
                <h3 className="font-semibold text-slate-800">{title}</h3>
                <p className="mt-1 text-sm text-slate-600">{text}</p>
              </article>
            ))}
          </div>
        </section>

        <div className="bg-emerald-50/50">
          <section id="how-it-works" className={SECTION}>
            <SectionHeading title="One record, from purchase to profit" text="Each step picks up from the last, so nothing needs entering twice." />
            <ol className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {STEPS.map(({ title, text }, i) => (
                <li key={title} className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-emerald-200">
                  <span aria-hidden="true" className="mb-3 flex size-8 items-center justify-center rounded-full bg-emerald-700 text-sm font-bold text-white">{i + 1}</span>
                  <h3 className="font-semibold text-slate-800">{title}</h3>
                  <p className="mt-1 text-sm text-slate-600">{text}</p>
                </li>
              ))}
            </ol>
          </section>
        </div>

        <section id="contact" className={SECTION}>
          <SectionHeading title="Talk to us" text="Have a question before you sign up, or need help with your account? Reach us any way you like." />
          <div className="mx-auto max-w-4xl">
            <LandingContact />
          </div>
        </section>

        <section className="bg-emerald-50">
          <div className="mx-auto w-full max-w-3xl px-4 py-14 text-center">
            <h2 className="text-2xl font-bold text-slate-800 sm:text-3xl">Ready to get your shop organised?</h2>
            <p className="mb-6 mt-2 text-slate-600">Create your account, confirm your email and start adding your vehicles.</p>
            <div className="flex flex-wrap justify-center gap-3">
              <LandingActions large />
            </div>
          </div>
        </section>
      </main>

      <footer className="border-t border-emerald-100">
        {/* the extra padding keeps the links clear of the WhatsApp button */}
        <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-6 pb-[calc(5rem+env(safe-area-inset-bottom))] text-sm text-slate-500 sm:pb-[calc(1.5rem+env(safe-area-inset-bottom))] sm:pr-52">
          <p>© Gari Becho · Beacon Soft Ltd</p>
          <div className="flex gap-5">
            <Link className="hover:text-emerald-700 hover:underline" href="/privacy">Privacy policy</Link>
            <Link className="hover:text-emerald-700 hover:underline" href="/terms">Terms of service</Link>
          </div>
        </div>
      </footer>

      <LandingWhatsApp />
    </div>
  );
}
