"use client";

import { useFetch } from "@/lib/hooks";
import { label } from "@/lib/format";
import { useSettings } from "@/lib/settings";

export type VehicleCategory = { id: number; shop_id: number | null; key: string; name: string; default_brand: string | null; default_model: string | null; default_color: string | null; default_cc: number | null };

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
 */
export function useTypeDefaults() {
  const { categories } = useCategories();
  const settings = useSettings();
  const overrides = settings.data?.type_defaults ?? {};

  const defaultsFor = (key: string, extra?: VehicleCategory): Record<DefaultField, string | number | null> => {
    const c = categories.find((x) => x.key === key) ?? extra;
    const o = overrides[key] ?? {};
    // Once the shop has set presets for a type (Settings), those are the whole list: a field it left out stays empty
    // instead of falling back to a default stored on the type, which the shop never sees in Settings.
    const own = Object.values(o).some((v) => v != null);
    const base = own ? undefined : c;
    return {
      brand: o.brand ?? base?.default_brand ?? null, model: o.model ?? base?.default_model ?? null, color: o.color ?? base?.default_color ?? null, cc: o.cc ?? base?.default_cc ?? null,
      year: o.year ?? null, fuel_type: o.fuel_type ?? null, mileage: o.mileage ?? null,
    };
  };

  return { defaultsFor, ready: categories.length > 0 && !!settings.data };
}
