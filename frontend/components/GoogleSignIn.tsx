"use client";

import { useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useT } from "@/lib/i18n";

type CodeClient = { requestCode: () => void };
type GoogleOAuth = {
  accounts: { oauth2: { initCodeClient: (c: { client_id: string; scope: string; ux_mode: "popup"; callback: (r: { code?: string; error?: string }) => void }) => CodeClient } };
};
declare global {
  interface Window { google?: GoogleOAuth }
}

export type AuthConfig = { signup_enabled: boolean; google: { enabled: boolean; client_id: string | null } };

const SCRIPT = "https://accounts.google.com/gsi/client";

function loadScript(): Promise<void> {
  if (window.google?.accounts) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const el = document.querySelector<HTMLScriptElement>(`script[src="${SCRIPT}"]`) ?? Object.assign(document.createElement("script"), { src: SCRIPT, async: true });
    el.addEventListener("load", () => resolve());
    el.addEventListener("error", () => reject());
    if (!el.isConnected) document.head.appendChild(el);
  });
}

/** What the super admin has switched on for the sign-in and sign-up pages. Null while loading or when the API cannot be reached. */
export function useAuthConfig() {
  const [config, setConfig] = useState<AuthConfig | null>(null);
  useEffect(() => {
    api<AuthConfig>("/auth/config").then((res) => setConfig(res.data)).catch(() => {});
  }, []);
  return config;
}

/** The message to show for a failed sign-in: the reason ("no account uses ...") sits on the email field, not in "Validation failed". */
export const authError = (e: unknown) => (e instanceof ApiError ? (e.errors?.email?.[0] ?? e.message) : "Something went wrong");

/**
 * Google's popup. `start()` opens it; `onCode` gets the one-time code to send to the server. `ready` is false until the
 * script has loaded, or for good when Google sign-in is off.
 */
export function useGoogleCode(clientId: string | null | undefined, onCode: (code: string) => Promise<void>) {
  const [client, setClient] = useState<CodeClient | null>(null);
  const [busy, setBusy] = useState(false);
  const onCodeRef = useRef(onCode);
  useEffect(() => { onCodeRef.current = onCode; });

  useEffect(() => {
    if (!clientId) return;
    let live = true;
    loadScript()
      .then(() => {
        if (!live || !window.google) return;
        setClient(window.google.accounts.oauth2.initCodeClient({
          client_id: clientId,
          scope: "openid email profile",
          ux_mode: "popup",
          callback: async (res) => {
            try {
              if (res.code) await onCodeRef.current(res.code); // no code: the popup was closed
            } finally {
              setBusy(false);
            }
          },
        }));
      })
      .catch(() => {}); // no Google button then; email still works
    return () => { live = false; };
  }, [clientId]);

  const start = () => {
    if (!client) return;
    setBusy(true);
    client.requestCode();
  };

  return { ready: !!client, busy, start };
}

export function GoogleButton({ label, busy, onClick }: { label: string; busy: boolean; onClick: () => void }) {
  const { t } = useT();
  return (
    <button
      type="button"
      disabled={busy}
      onClick={onClick}
      className="flex h-10 w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
    >
      <svg viewBox="0 0 48 48" className="size-5" aria-hidden="true">
        <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z" />
        <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
        <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z" />
        <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z" />
      </svg>
      {busy ? t("Please wait...") : t(label)}
    </button>
  );
}

export const OrDivider = () => {
  const { t } = useT();
  return <div className="flex items-center gap-3 text-xs text-slate-400"><span className="h-px flex-1 bg-slate-200" />{t("or")}<span className="h-px flex-1 bg-slate-200" /></div>;
};

/** "Sign in with Google" on the sign-in page, shown only once the super admin has turned Google sign-in on. */
export function GoogleSignIn({ clientId }: { clientId: string | null | undefined }) {
  const { loginWithGoogle } = useAuth();
  const google = useGoogleCode(clientId, async (code) => {
    try {
      await loginWithGoogle(code);
      toast.success("Logged in");
    } catch (e) {
      toast.error(authError(e));
    }
  });

  if (!google.ready) return null;

  return (
    <>
      <OrDivider />
      <GoogleButton label="Sign in with Google" busy={google.busy} onClick={google.start} />
    </>
  );
}
