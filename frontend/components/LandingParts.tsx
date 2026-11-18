"use client";

import Link from "next/link";
import { ArrowRight, Check, Clock, Download, Mail, MapPin, MessageCircle, Minus, Package, Phone, Share, X, type LucideIcon } from "lucide-react";
import { homePath, useAuth } from "@/lib/auth";
import { featureLabel } from "@/lib/billing";
import { CARD, TILE, TONES, tint } from "@/lib/landing";
import { useFetch } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { dismissIosHint, installApp, useCanInstall, useShowIosHint } from "@/lib/install";
import type { LandingInfo, PlanT } from "@/lib/types";
import { Loading } from "@/components/app-ui";
import { ContactForm } from "@/components/forms/ContactForm";
import { PlanPrice } from "@/components/PlanPrice";

const PRIMARY = "inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-emerald-700 to-green-700 font-semibold text-white shadow-sm hover:from-emerald-800 hover:to-green-800";
const OUTLINE = "inline-flex items-center justify-center rounded-lg border border-emerald-300 bg-white font-semibold text-emerald-700 hover:bg-emerald-50";

const WHATSAPP_GREETING = "Hello, I would like to know more about Gari Becho.";

// every part of the page asks for the same thing; api() sends it once
const useLanding = () => useFetch<LandingInfo>("/landing");

const whatsappUrl = (number: string, greeting: string) => `https://wa.me/${number.replace(/\D/g, "")}?text=${encodeURIComponent(greeting)}`;

/** Sign in and sign up, or the way back to work for someone already signed in. Sign up goes away only once the settings say it is closed. */
export function LandingActions({ large = false }: { large?: boolean }) {
  const { user } = useAuth();
  const { data } = useLanding();
  const size = large ? "h-12 px-6 text-base" : "h-9 px-3 text-sm";

  if (user) return <Link href={homePath(user)} className={`${PRIMARY} ${size}`}>Open dashboard <ArrowRight className="size-4" /></Link>;

  return (
    <>
      <Link href="/login" className={`${OUTLINE} ${size}`}>Sign in</Link>
      {data?.signup_enabled !== false && <Link href="/signup" className={`${PRIMARY} ${size}`}>Sign up</Link>}
    </>
  );
}

/** Offers to install the app, once the browser is ready to and it is not installed already. Just the icon on a phone, where the header is full. */
export function LandingInstall() {
  const canInstall = useCanInstall();
  if (!canInstall) return null;

  return (
    <button type="button" aria-label="Install app" onClick={installApp} className={`${OUTLINE} h-9 w-9 gap-2 text-sm lg:w-auto lg:px-3`}>
      <Download className="size-4 shrink-0" /> <span className="hidden lg:inline">Install app</span>
    </button>
  );
}

/** How to install on iPhone and iPad, where Safari has no install event for the button above to use. */
export function LandingIosHint() {
  const show = useShowIosHint();
  if (!show) return null;

  return (
    <div className="border-b border-emerald-200 bg-emerald-50">
      <div className="mx-auto flex w-full max-w-6xl items-start gap-2 px-4 py-2 text-sm text-slate-700">
        <Share className="mt-0.5 size-4 shrink-0 text-emerald-700" aria-hidden="true" />
        <p className="min-w-0 flex-1">Install Gari Becho: tap the Share button, then &apos;Add to Home Screen&apos;.</p>
        <button type="button" aria-label="Dismiss" onClick={dismissIosHint} className="-m-1 flex size-7 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-emerald-100 hover:text-emerald-700"><X className="size-4" /></button>
      </div>
    </div>
  );
}

/** The EN / বাং switch. The dashboard has its own in the menu; signed-out visitors need one here. */
export function LandingLanguage() {
  const { lang, setLang } = useT();
  const option = (value: "en" | "bn", text: string, label: string) => (
    <button type="button" aria-label={label} aria-pressed={lang === value} onClick={() => setLang(value)} className={`px-2 py-1.5 ${lang === value ? "bg-emerald-700 text-white" : "text-emerald-700 hover:bg-emerald-50"}`}>{text}</button>
  );

  return (
    <div data-no-translate className="flex overflow-hidden rounded-lg border border-emerald-300 bg-white text-xs font-semibold" role="group" aria-label="Language">
      {option("en", "EN", "English")}
      {option("bn", "বাং", "বাংলা")}
    </div>
  );
}

function ContactCard({ icon: Icon, tone, label, value, href, external }: { icon: LucideIcon; tone: string; label: string; value: string; href?: string; external?: boolean }) {
  const text = <span data-no-translate className="mt-1 block break-words font-medium text-slate-800">{value}</span>;

  return (
    <div className="flex items-start gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-emerald-200">
      <span className={`flex size-10 shrink-0 items-center justify-center rounded-lg ${tone}`}><Icon className="size-5" /></span>
      <div className="min-w-0">
        <p className="text-sm text-slate-500">{label}</p>
        {href ? <a href={href} className="hover:text-emerald-700 hover:underline" {...(external && { target: "_blank", rel: "noopener noreferrer" })}>{text}</a> : text}
      </div>
    </div>
  );
}

/** The contact form, once a support email is saved and mail can really leave the server, beside the details from System settings. Without a support email, visitors are told support is unavailable. */
export function LandingContact() {
  const { data, loading } = useLanding();
  const { t } = useT();
  if (loading && !data) return <Loading />;

  const email = data?.support_email;

  return (
    <div className={data?.contact_form ? "grid items-start gap-6 lg:grid-cols-2" : "mx-auto grid max-w-md gap-3"}>
      {data?.contact_form && <ContactForm />}
      {!email && <p role="status" className="rounded-xl bg-amber-50 p-4 text-center text-sm font-medium text-amber-800">Support is unavailable for now. It will be available soon.</p>}
      <div className="grid gap-3">
        {email && <ContactCard icon={Mail} tone="bg-sky-100 text-sky-600" label="Email" value={email} href={`mailto:${email}`} />}
        {data?.support_phone && <ContactCard icon={Phone} tone="bg-emerald-100 text-emerald-600" label="Phone" value={data.support_phone} href={`tel:${data.support_phone.replace(/[\s-]/g, "")}`} />}
        {data?.whatsapp && <ContactCard icon={MessageCircle} tone="bg-green-100 text-green-600" label="WhatsApp" value={data.whatsapp} href={whatsappUrl(data.whatsapp, t(WHATSAPP_GREETING))} external />}
        {data?.support_hours && <ContactCard icon={Clock} tone="bg-amber-100 text-amber-600" label="Support hours" value={data.support_hours} />}
        {data?.address && <ContactCard icon={MapPin} tone="bg-rose-100 text-rose-600" label="Address" value={data.address} />}
      </div>
    </div>
  );
}

/** Every plan the super admin offers, with what each one includes. Signed-out visitors go to sign up; a shop owner goes to billing to choose. */
export function LandingPlans() {
  const { user } = useAuth();
  const { t } = useT();
  const { data, loading, error } = useFetch<PlanT[]>("/plans");
  if (loading && !data) return <Loading />;
  if (error) return <p role="status" className="text-center text-sm text-slate-500">{t("Plans could not be loaded. Please refresh the page.")}</p>;
  const plans = data ?? [];
  if (!plans.length) return <p role="status" className="text-center text-sm text-slate-500">{t("Plans will be announced soon.")}</p>;

  // the middle of the list is the usual pick
  const featured = plans.length > 2 ? plans[Math.floor(plans.length / 2)].id : null;
  const cta = user ? (user.role === "shop_owner" ? "/billing" : homePath(user)) : "/signup";

  return (
    <div className={`mx-auto grid gap-4 ${plans.length === 1 ? "max-w-sm" : plans.length === 2 ? "max-w-3xl sm:grid-cols-2" : "sm:grid-cols-2 lg:grid-cols-3"}`}>
      {plans.map((plan, i) => {
        const top = plan.id === featured;
        const tone = TONES[i % TONES.length];
        return (
          <article key={plan.id} className={`${CARD} ${tint(tone)} relative flex flex-col`}>
            {top && <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-emerald-600 px-3 py-0.5 text-xs font-semibold text-white">{t("Most popular")}</span>}
            <span className={`${TILE} ${tone}`}><Package className="size-6" /></span>
            <h3 className="font-semibold text-slate-800">{plan.name}</h3>
            <p className="mt-3"><PlanPrice plan={plan} large /></p>
            {plan.trial_days > 0 && <p className="mt-1 text-sm font-medium text-emerald-700">{`${plan.trial_days} ${t("days free trial")}`}</p>}
            {plan.description && <p className="mt-3 text-sm text-slate-600">{plan.description}</p>}
            <ul className="mt-4 flex-1 space-y-2 text-sm">
              {Object.entries(plan.features ?? {}).map(([key, value]) => {
                const off = value === "0";
                const amount = value === null ? t("Unlimited") : value !== "1" && value !== "0" ? value : null;
                return (
                  <li key={key} className={`flex items-start gap-2 ${off ? "text-slate-400" : "text-slate-700"}`}>
                    {off ? <Minus className="mt-0.5 size-4 shrink-0" aria-hidden="true" /> : <Check className="mt-0.5 size-4 shrink-0 text-emerald-600" aria-hidden="true" />}
                    <span>{off && <span className="sr-only">{t("Not included")}: </span>}{t(featureLabel(key))}{amount !== null && <>: <strong className="font-semibold">{amount}</strong></>}</span>
                  </li>
                );
              })}
            </ul>
            <Link href={cta} className={`mt-6 h-11 px-4 text-sm ${PRIMARY}`}>{t(user ? "Choose plan" : plan.trial_days > 0 ? "Start free trial" : "Get started")}</Link>
          </article>
        );
      })}
    </div>
  );
}

/** A chat button that stays in the corner, once a WhatsApp number is saved. */
export function LandingWhatsApp() {
  const { data } = useLanding();
  const { t } = useT();
  if (!data?.whatsapp) return null;

  return (
    <a
      href={whatsappUrl(data.whatsapp, t(WHATSAPP_GREETING))}
      target="_blank"
      rel="noopener noreferrer"
      className="fixed bottom-[calc(1rem+env(safe-area-inset-bottom))] right-4 z-40 flex h-12 items-center gap-2 rounded-full bg-emerald-700 px-4 text-sm font-semibold text-white shadow-lg hover:bg-emerald-800"
    >
      <MessageCircle className="size-5" /> <span>Chat on WhatsApp</span>
    </a>
  );
}
