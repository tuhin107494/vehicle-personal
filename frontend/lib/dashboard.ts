"use client";

import { useCallback, useSyncExternalStore } from "react";

const KEY = "vm_hidden_dashboard";
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

/** Dashboard sections this device has hidden, by key. Kept in the browser, so it is per device. */
export function useHiddenDashboard() {
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

export const DASHBOARD_SECTIONS = [
  ["total_purchase", "Total purchase"],
  ["total_sales", "Total sales"],
  ["total_due", "Total due"],
  ["charts", "Charts"],
  ["attention", "Attention"],
  ["dues", "Dues today (installments and due)"],
  ["investors", "Investor payments today"],
  ["today", "Today's figures"],
] as const;
