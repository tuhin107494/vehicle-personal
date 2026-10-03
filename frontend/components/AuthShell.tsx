"use client";

import type { ReactNode } from "react";
import { Car } from "lucide-react";
import { Card } from "@/components/app-ui";
import { CardContent } from "@/components/ui/card";
import { LoginPoster } from "@/components/LoginPoster";

/** The sign-in card on the poster background, shared by the forgot-password and reset-password pages. */
export function AuthShell({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
  return (
    <main className="relative flex min-h-screen items-center justify-center px-4 py-6">
      <LoginPoster />
      <div className="relative z-10 w-full max-w-sm">
        <Card className="w-full bg-white/95 shadow-lg ring-1 ring-emerald-300">
          <CardContent className="space-y-4 pt-2">
            <div className="text-center">
              <span className="mx-auto mb-2 flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-600 to-green-500 text-white"><Car className="size-6" /></span>
              <h1 className="text-xl font-bold text-slate-800">{title}</h1>
              <p className="text-sm text-slate-500">{subtitle}</p>
            </div>
            {children}
          </CardContent>
        </Card>
      </div>
    </main>
  );
}
