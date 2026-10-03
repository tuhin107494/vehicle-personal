"use client";

import { useFetch } from "@/lib/hooks";
import { label } from "@/lib/format";
import { useSettings } from "@/lib/settings";

export type VehicleCategory = { id: number; key: string; name: string; default_brand: string | null; default_model: string | null; default_color: string | null; default_cc: number | null };

/** Vehicle categories are created by the super admin; everything reads them from the API. */
export function useCategories() {
  const { data, reload } = useFetch<VehicleCategory[]>("/vehicle-categories");
  const categories = data ?? [];
  return { categories, reload, nameOf: (key?: string | null) => categories.find((c) => c.key === key)?.name ?? label(key) };
}

export type DefaultField = "brand" | "model" | "color" | "cc" | "year" | "fuel_type" | "mileage";
export const DEFAULT_FIELDS: DefaultField[] = ["brand", "model", "color", "cc", "year", "fuel_type", "mileage"];

/**
 * What a vehicle type pre-fills: the shop's own settings first, then the type's built-in default.
 * `isAuto` tells whether a value was itself filled in by some type (so it is safe to replace).
 */
export function useTypeDefaults() {
  const { categories } = useCategories();
  const settings = useSettings();
  const overrides = settings.data?.type_defaults ?? {};

  const defaultsFor = (key: string, extra?: VehicleCategory): Record<DefaultField, string | number | null> => {
    const c = categories.find((x) => x.key === key) ?? extra;
    const o = overrides[key] ?? {};
    return {
      brand: o.brand ?? c?.default_brand ?? null, model: o.model ?? c?.default_model ?? null, color: o.color ?? c?.default_color ?? null, cc: o.cc ?? c?.default_cc ?? null,
      year: o.year ?? null, fuel_type: o.fuel_type ?? null, mileage: o.mileage ?? null,
    };
  };

  const isAuto = (field: DefaultField, value: string) => categories.some((c) => String(defaultsFor(c.key)[field] ?? "") === value);

  return { defaultsFor, isAuto, ready: categories.length > 0 && !!settings.data };
}
