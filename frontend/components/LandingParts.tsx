"use client";

import Link from "next/link";
import { ArrowRight, Clock, Download, Mail, MapPin, MessageCircle, Phone, Share, X, type LucideIcon } from "lucide-react";
import { homePath, useAuth } from "@/lib/auth";
import { useFetch } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { dismissIosHint, installApp, useCanInstall, useShowIosHint } from "@/lib/install";
import type { LandingInfo } from "@/lib/types";
import { Loading } from "@/components/app-ui";
import { ContactForm } from "@/components/forms/ContactForm";

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

function ContactCard({ icon: Icon, label, value, href, external }: { icon: LucideIcon; label: string; value: string; href?: string; external?: boolean }) {
  const text = <span data-no-translate className="mt-1 block break-words font-medium text-slate-800">{value}</span>;

  return (
    <div className="flex items-start gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-emerald-200">
      <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700"><Icon className="size-5" /></span>
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
        {email && <ContactCard icon={Mail} label="Email" value={email} href={`mailto:${email}`} />}
        {data?.support_phone && <ContactCard icon={Phone} label="Phone" value={data.support_phone} href={`tel:${data.support_phone.replace(/[\s-]/g, "")}`} />}
        {data?.whatsapp && <ContactCard icon={MessageCircle} label="WhatsApp" value={data.whatsapp} href={whatsappUrl(data.whatsapp, t(WHATSAPP_GREETING))} external />}
        {data?.support_hours && <ContactCard icon={Clock} label="Support hours" value={data.support_hours} />}
        {data?.address && <ContactCard icon={MapPin} label="Address" value={data.address} />}
      </div>
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
