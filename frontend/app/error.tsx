"use client";

import { Button } from "@/components/app-ui";

/** Shown when a page crashes: a message and a way to try again, instead of a blank screen. */
export default function GlobalError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-3 px-4 text-center">
      <h1 className="text-xl font-bold text-slate-800">Something went wrong</h1>
      <p className="max-w-sm text-sm text-slate-600">The page could not be shown. Try again, and if it keeps happening, reload the page.</p>
      <Button onClick={reset}>Try again</Button>
    </main>
  );
}
