"use client";

import { useCallback, useSyncExternalStore } from "react";

const KEY = "vm_hidden_menu";
const listeners = new Set<() => void>();
let cache: string | null = null;

const read = () => {
  try {
    return localStorage.getItem(KEY) ?? "[]";
  } catch {
    return "[]";
  }
};
const subscribe = (cb: () => void) => {
  listeners.add(cb);
  return () => { listeners.delete(cb); };
};
const snapshot = () => (cache ??= read());

/** Menu items this device has hidden, by their href. Kept in the browser, so it is per device. */
export function useHiddenMenu() {
  const raw = useSyncExternalStore(subscribe, snapshot, () => "[]");
  const hidden: string[] = JSON.parse(raw);

  const setHidden = useCallback((next: string[]) => {
    cache = JSON.stringify(next);
    try {
      localStorage.setItem(KEY, cache);
    } catch {}
    listeners.forEach((l) => l());
  }, []);

  return { hidden, setHidden };
}
