import type { Metadata } from "next";
import Link from "next/link";
import { BadgeDollarSign, CalendarClock, Car, ChartLine, FileCheck2, FileText, HandCoins, Languages, Shield, Smartphone, Ticket, Truck, UserCog, type LucideIcon } from "lucide-react";
import { LandingActions, LandingContact, LandingInstall, LandingIosHint, LandingLanguage, LandingPlans, LandingWhatsApp } from "@/components/LandingParts";
import { LandingVideo } from "@/components/LandingVideo";
import { CARD, TILE, tint } from "@/lib/landing";
import { SITE_DESCRIPTION, SITE_NAME, SITE_URL } from "@/lib/site";

export const metadata: Metadata = {
  title: "Gari Becho · Vehicle shop management",
  description: SITE_DESCRIPTION,
  alternates: { canonical: "/" },
};

// tells Google and AI search what the product is; fixed text only, so the escaped JSON is safe to inline
const JSON_LD = JSON.stringify({
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Organization",
      "@id": `${SITE_URL}/#organization`,
      name: "Beacon Soft Ltd",
      url: SITE_URL,
      logo: `${SITE_URL}/icons/icon-512.png`,
    },
    {
      "@type": "WebSite",
      "@id": `${SITE_URL}/#website`,
      name: SITE_NAME,
      alternateName: "গাড়ি বেচো",
      url: SITE_URL,
      inLanguage: ["en", "bn"],
      publisher: { "@id": `${SITE_URL}/#organization` },
    },
    {
      "@type": "SoftwareApplication",
      name: SITE_NAME,
      alternateName: "গাড়ি বেচো",
      url: SITE_URL,
      description: SITE_DESCRIPTION,
      applicationCategory: "BusinessApplication",
      // a web app that installs from the browser, not a store app
      operatingSystem: "Web browser",
      inLanguage: ["en", "bn"],
      featureList: ["Stock and purchases", "Sales", "Bookings", "Installments and dues", "Investors", "Registration", "Reports", "Team roles"],
      publisher: { "@id": `${SITE_URL}/#organization` },
    },
  ],
}).replace(/</g, "\\u003c");

const FEATURES: { icon: LucideIcon; tone: string; title: string; text: string }[] = [
  { icon: Truck, tone: "bg-emerald-100 text-emerald-600", title: "Stock and purchases", text: "Record each purchase and see every vehicle in stock, with its chassis and engine number." },
  { icon: BadgeDollarSign, tone: "bg-sky-100 text-sky-600", title: "Sales", text: "Sell for cash or on installments, and print the sale memo on the spot." },
  { icon: Ticket, tone: "bg-amber-100 text-amber-600", title: "Bookings", text: "Hold a vehicle for a buyer with an advance, and keep an eye on the deadline." },
  { icon: CalendarClock, tone: "bg-rose-100 text-rose-600", title: "Installments and dues", text: "Follow every installment and promise to pay, and see who to call today." },
  { icon: HandCoins, tone: "bg-teal-100 text-teal-600", title: "Investors", text: "Record who funded each vehicle and settle their profit when it sells." },
];

const HIGHLIGHTS: { icon: LucideIcon; tone: string; text: string }[] = [
  { icon: Languages, tone: "text-sky-300", text: "In English and Bangla" },
  { icon: Smartphone, tone: "text-amber-300", text: "On phone and computer" },
  { icon: Shield, tone: "text-emerald-300", text: "Your records stay private to your shop" },
];

const CONTROLS: { icon: LucideIcon; tone: string; title: string; text: string }[] = [
  { icon: ChartLine, tone: "bg-emerald-100 text-emerald-600", title: "Investor Accounting", text: "Track funding per vehicle and settle profits on sale." },
  { icon: FileCheck2, tone: "bg-sky-100 text-sky-600", title: "Registration Audit", text: "Monitor bank chalans through document handovers." },
  { icon: FileText, tone: "bg-amber-100 text-amber-600", title: "Instant Reports", text: "Daily profit, sales, and due reports in PDF." },
  { icon: UserCog, tone: "bg-teal-100 text-teal-600", title: "Role-Based Staff", text: "Granular access controls for staff logins." },
];

const STEP_TONES = ["bg-sky-600", "bg-emerald-600", "bg-amber-500", "bg-teal-600"];
const STEP_CARDS = ["text-sky-600", "text-emerald-600", "text-amber-600", "text-teal-600"];

const STEPS = [
  { title: "Buy", text: "Record the purchase from a supplier. The vehicle joins your stock." },
  { title: "Sell", text: "Choose the vehicle and the buyer, then take cash or set up installments." },
  { title: "Collect", text: "Take payments as they fall due. The balance updates by itself." },
  { title: "Settle", text: "Pay your investors their share and see the figures in your reports." },
];


const SECTION = "mx-auto w-full max-w-6xl scroll-mt-[calc(5rem+env(safe-area-inset-top))] px-4 py-8 sm:py-10";
const NAV_LINK = "text-sm font-medium text-slate-600 hover:text-emerald-700";

function SectionHeading({ title, text, eyebrow, id }: { title: string; text: string; eyebrow?: string; id?: string }) {
  return (
    <div className="mx-auto mb-8 max-w-2xl text-center">
      {eyebrow && <p className="mb-2 text-xs font-bold uppercase tracking-widest text-emerald-600">{eyebrow}</p>}
      <h2 id={id} className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{title}</h2>
      <span aria-hidden="true" className="mx-auto mt-3 block h-1 w-12 rounded-full bg-emerald-500" />
      <p className="mt-3 text-slate-600">{text}</p>
    </div>
  );
}

/** The public front door: rendered on the server so visitors and search engines get the content at once. */
export default function LandingPage() {
  return (
    <div className="flex min-h-screen flex-col bg-white">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON_LD }} />
      <header className="sticky top-0 z-30 border-b border-emerald-100 bg-white/90 pt-[env(safe-area-inset-top)] shadow-sm backdrop-blur-md">
        <div className="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-2 px-4">
          <Link href="/" aria-label="Gari Becho" className="flex min-w-0 items-center gap-2 font-bold text-slate-800">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-600 to-green-500 text-white"><Car className="size-5" /></span>
            <span className="hidden truncate sm:inline">Gari Becho</span>
          </Link>
          <nav aria-label="Page sections" className="hidden items-center gap-6 lg:flex">
            <a className={NAV_LINK} href="#features">Features</a>
            <a className={NAV_LINK} href="#how-it-works">How it works</a>
            <a className={NAV_LINK} href="#pricing">Pricing</a>
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
          <div className="pointer-events-none absolute -bottom-24 -left-20 size-72 rounded-full bg-emerald-100/60" />
          <div className="relative mx-auto grid w-full max-w-6xl items-center gap-10 px-4 pb-4 pt-14 sm:pb-6 sm:pt-20 lg:grid-cols-2">
            <div>
              <p className="mb-4 inline-block rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-700">Vehicle shop management</p>
              <h1 className="text-4xl font-extrabold leading-tight tracking-tight text-slate-900 sm:text-5xl">Run your vehicle shop from one place</h1>
              <p className="mt-5 max-w-xl text-lg leading-relaxed text-slate-600">Gari Becho keeps your stock, purchases, sales, installments, dues and investors together, so you always know what you own, what you sold and what you are owed.</p>
              <div className="mt-8 flex flex-wrap gap-3">
                <LandingActions large />
              </div>
            </div>
            <LandingVideo />
          </div>
        </section>

        <section aria-label="Highlights" className="px-4 pb-5 pt-2">
          <ul className="mx-auto flex w-fit max-w-full flex-wrap items-center justify-center gap-x-6 gap-y-2 rounded-xl bg-gradient-to-br from-emerald-800 to-emerald-950 px-5 py-2.5 shadow-sm ring-1 ring-emerald-900/20">
            {HIGHLIGHTS.map(({ icon: Icon, tone, text }) => (
              <li key={text} className="flex items-center gap-2 text-sm font-medium text-emerald-50"><Icon className={`size-4 shrink-0 ${tone}`} /> <span>{text}</span></li>
            ))}
          </ul>
        </section>

        <section id="features" className={SECTION}>
          <SectionHeading title="Everything your shop keeps track of" text="From the day you buy a vehicle to the day the last installment comes in." />
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {FEATURES.map(({ icon: Icon, tone, title, text }) => (
              <article key={title} className={`${CARD} ${tint(tone)}`}>
                <span className={`${TILE} ${tone}`}><Icon className="size-6" /></span>
                <h3 className="font-semibold text-slate-800">{title}</h3>
                <p className="mt-1 text-sm text-slate-600">{text}</p>
              </article>
            ))}
          </div>
        </section>

        <section aria-labelledby="controls-heading" className={SECTION}>
          <SectionHeading id="controls-heading" eyebrow="Enterprise Controls" title="Financial & Team Management" text="Maintain full oversight across investor returns, document workflows, and staff permissions." />
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {CONTROLS.map(({ icon: Icon, tone, title, text }) => (
              <article key={title} className={`${CARD} ${tint(tone)}`}>
                <span className={`${TILE} ${tone}`}><Icon className="size-6" /></span>
                <h3 className="font-semibold text-slate-800">{title}</h3>
                <p className="mt-1 text-sm text-slate-600">{text}</p>
              </article>
            ))}
          </div>
        </section>

        <section id="how-it-works" className={SECTION}>
          <SectionHeading title="One record, from purchase to profit" text="Each step picks up from the last, so nothing needs entering twice." />
          <ol className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {STEPS.map(({ title, text }, i) => (
              <li key={title} className={`${CARD} ${tint(STEP_CARDS[i])}`}>
                <span aria-hidden="true" className={`mb-4 flex size-12 items-center justify-center rounded-full ${STEP_TONES[i]} text-lg font-bold text-white`}>{i + 1}</span>
                <h3 className="font-semibold text-slate-800">{title}</h3>
                <p className="mt-1 text-sm text-slate-600">{text}</p>
              </li>
            ))}
          </ol>
        </section>

        <section id="pricing" className={SECTION}>
          <SectionHeading title="Plans and pricing" text="Start with a free trial. Change or cancel your plan any time from your dashboard." />
          <LandingPlans />
        </section>

        <section id="contact" className={SECTION}>
          <SectionHeading title="Talk to us" text="Have a question before you sign up, or need help with your account? Reach us any way you like." />
          <div className="mx-auto max-w-4xl">
            <LandingContact />
          </div>
        </section>

        <section className="px-4 py-8 pb-12">
          <div className="mx-auto w-full max-w-4xl rounded-3xl bg-gradient-to-br from-emerald-50 to-emerald-100 px-6 py-12 text-center shadow-sm ring-1 ring-emerald-200">
            <h2 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Ready to get your shop organised?</h2>
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
