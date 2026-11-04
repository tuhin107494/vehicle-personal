"use client";

import { useEffect } from "react";
import { useFetch } from "@/lib/hooks";
import { todayStr } from "@/lib/format";

export type TypeDefaults = { brand?: string; model?: string; color?: string; cc?: number; year?: number; fuel_type?: string; mileage?: number };

export type Settings = {
  type_defaults: Record<string, TypeDefaults>;
  purchase: { vehicle_type: string | null; prices: Record<string, number>; from_customer: boolean };
  sale: { vehicle_type: string | null; prices: Record<string, number> };
  collections: { recall_days: number; keep_selection: boolean };
  forms: { auto_date: boolean; memo_no: boolean };
  booking: { days: number; on_cancel: "refund" | "keep"; on_expire: "refund" | "keep" };
};

/** The shop's primary form values (what the purchase and sale forms start with). */
export function useSettings() {
  return useFetch<Settings>("/settings");
}

/** Today's date when the shop's "fill in today's date" setting is on, otherwise empty: what a form's date field starts with. */
export function useAutoDate(): string {
  return useSettings().data?.forms?.auto_date ? todayStr() : "";
}

/** The settings arrive after the form is first shown: fill the empty date in once they say so (later edits and clears are left alone). */
export function useFillDate(date: string, isEmpty: () => boolean, fill: (date: string) => void) {
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { if (date && isEmpty()) fill(date); }, [date]);
}

/** Whether the shop shows the optional memo number field on the Sale and Due collection forms (on unless turned off). */
export function useMemoField(): boolean {
  return useSettings().data?.forms?.memo_no !== false;
}
