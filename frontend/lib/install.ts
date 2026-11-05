"use client";

import { useSyncExternalStore } from "react";

type InstallPrompt = Event & { prompt: () => Promise<void> };

let waiting: InstallPrompt | null = null;
const listeners = new Set<() => void>();

const setWaiting = (next: InstallPrompt | null) => {
  waiting = next;
  listeners.forEach((notify) => notify());
};

// Chrome sends this once, and can send it before React has loaded or on a page without the button, so it is caught here and kept.
if (typeof window !== "undefined") {
  window.addEventListener("beforeinstallprompt", (e) => {
    e.preventDefault(); // our own button asks, not Chrome's bar
    setWaiting(e as InstallPrompt);
  });
  window.addEventListener("appinstalled", () => setWaiting(null));
}

const subscribe = (onChange: () => void) => {
  listeners.add(onChange);
  return () => {
    listeners.delete(onChange);
  };
};

// iOS has its own older flag for an app opened from the home screen
const isInstalled = () => window.matchMedia("(display-mode: standalone)").matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;

const snapshot = () => (isInstalled() ? null : waiting);

/** Whether the browser is ready to install the app and it is not already running as installed. */
export const useCanInstall = () => useSyncExternalStore(subscribe, snapshot, () => null) !== null;

const IOS_HINT_KEY = "vm_ios_hint_dismissed";
const IOS_HINT_HIDDEN_FOR = 30 * 24 * 60 * 60 * 1000;
let iosHintDismissed = false; // kept in memory too, for browsers that block storage

// iPadOS calls itself a Mac, but no Mac has a touch screen
const isIos = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);

function dismissedLately() {
  if (iosHintDismissed) return true;
  try {
    return Date.now() - Number(localStorage.getItem(IOS_HINT_KEY)) < IOS_HINT_HIDDEN_FOR;
  } catch {
    return false;
  }
}

/** Whether to show iPhone and iPad visitors how to install: not once installed, nor for 30 days after they close the hint. */
export const useShowIosHint = () => useSyncExternalStore(subscribe, () => isIos() && !isInstalled() && !dismissedLately(), () => false);

export function dismissIosHint() {
  iosHintDismissed = true;
  try {
    localStorage.setItem(IOS_HINT_KEY, String(Date.now()));
  } catch {}
  listeners.forEach((notify) => notify());
}

/** Opens the browser's install question. A prompt works only once, so it is cleared whatever the answer. */
export async function installApp() {
  const prompt = waiting;
  setWaiting(null);
  await prompt?.prompt();
}
