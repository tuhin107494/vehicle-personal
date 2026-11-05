"use client";

import { useEffect } from "react";
import "@/lib/install"; // starts listening for Chrome's install event on every page, not just the one with the button

/** Registers the service worker in production. In development it would keep serving old files. */
export function ServiceWorkerRegister() {
  useEffect(() => {
    if (process.env.NODE_ENV !== "production" || !("serviceWorker" in navigator)) return;
    // the app works the same without it, so a failure here only costs installing and the offline page
    navigator.serviceWorker.register("/sw.js", { scope: "/", updateViaCache: "none" }).catch(() => {});
  }, []);

  return null;
}
