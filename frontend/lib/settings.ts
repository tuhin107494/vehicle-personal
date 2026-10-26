"use client";

import { useFetch } from "@/lib/hooks";

export type TypeDefaults = { brand?: string; model?: string; color?: string; cc?: number; year?: number; fuel_type?: string; mileage?: number };

export type Settings = {
  type_defaults: Record<string, TypeDefaults>;
  purchase: { vehicle_type: string | null; prices: Record<string, number> };
  sale: { vehicle_type: string | null; prices: Record<string, number> };
  collections: { recall_days: number };
};

/** The shop's primary form values (what the purchase and sale forms start with). */
export function useSettings() {
  return useFetch<Settings>("/settings");
}
