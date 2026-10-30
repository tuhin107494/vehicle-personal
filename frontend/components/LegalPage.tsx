import type { ReactNode } from "react";
import Link from "next/link";
import { Car } from "lucide-react";
import { LoginPoster } from "@/components/LoginPoster";

export const CONTACT_EMAIL = "beaconsoftltd@gmail.com";

/** A public reading page (privacy policy, terms) on the sign-in poster background; needs no sign-in. */
export function LegalPage({ title, updated, children }: { title: string; updated: string; children: ReactNode }) {
  return (
    <main className="relative flex min-h-screen justify-center px-4 py-8">
      <LoginPoster />
      <article className="relative z-10 w-full max-w-3xl rounded-xl bg-white/95 p-6 shadow-lg ring-1 ring-emerald-300 sm:p-10">
        <header className="mb-6 flex items-center gap-3 border-b border-slate-200 pb-5">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-600 to-green-500 text-white"><Car className="size-6" /></span>
          <div>
            <h1 className="text-xl font-bold text-slate-800 sm:text-2xl">{title}</h1>
            <p className="text-sm text-slate-500">Vehicle Hub · Last updated {updated}</p>
          </div>
        </header>
        <div className="space-y-6 text-sm leading-relaxed text-slate-700 [&_h2]:mb-2 [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-slate-800 [&_li]:ml-5 [&_li]:list-disc [&_ul]:space-y-1">
          {children}
        </div>
        <footer className="mt-8 flex flex-wrap gap-x-5 gap-y-2 border-t border-slate-200 pt-5 text-sm">
          <Link className="font-medium text-emerald-700 hover:underline" href="/login">Sign in</Link>
          <Link className="text-slate-500 hover:text-emerald-700 hover:underline" href="/privacy">Privacy policy</Link>
          <Link className="text-slate-500 hover:text-emerald-700 hover:underline" href="/terms">Terms of service</Link>
        </footer>
      </article>
    </main>
  );
}

/** The contact email as a mailto link. */
export function ContactEmail() {
  return <a className="font-medium text-emerald-700 underline" href={`mailto:${CONTACT_EMAIL}`}>{CONTACT_EMAIL}</a>;
}
