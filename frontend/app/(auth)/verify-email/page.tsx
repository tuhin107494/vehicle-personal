"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { type Session, useAuth } from "@/lib/auth";
import { AuthShell } from "@/components/AuthShell";
import { ResendLink } from "@/components/ResendLink";
import { Loading } from "@/components/app-ui";

/** Opened from the sign-up confirmation email: confirms the email, which opens the shop and signs the owner in. */
function Confirm() {
  const router = useRouter();
  const params = useSearchParams();
  const { startSession } = useAuth();
  const id = params.get("id");
  const expires = params.get("expires");
  const token = params.get("token");
  const [error, setError] = useState("");
  // the link works once: send it once, even when React runs the effect twice in development
  const sent = useRef(false);

  useEffect(() => {
    if (!id || !expires || !token || sent.current) return;
    sent.current = true;
    api<Session>("/signup/confirm", { method: "POST", body: { id: Number(id), expires: Number(expires), token } })
      .then((res) => {
        startSession(res.data);
        toast.success(res.message);
        router.replace("/");
      })
      .catch((e) => setError(e instanceof ApiError ? (e.errors?.token?.[0] ?? e.message) : "Something went wrong"));
  }, [id, expires, token, startSession, router]);

  if (id && expires && token && !error) {
    return <AuthShell title="Confirming your email" subtitle="One moment"><Loading /></AuthShell>;
  }

  return (
    <AuthShell title="Confirm your email" subtitle={error ? "This link did not work" : "Get a new confirmation link"}>
      {error && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{error}</p>}
      <p className="text-sm text-slate-600">Enter the email you signed up with and we will send a new link. Already confirmed? Just sign in.</p>
      <ResendLink />
      <p className="text-center text-sm"><Link className="font-medium text-emerald-700 underline" href="/login">Back to sign in</Link></p>
    </AuthShell>
  );
}

export default function VerifyEmailPage() {
  // reading the link needs the browser, so the page waits for it
  return <Suspense><Confirm /></Suspense>;
}
