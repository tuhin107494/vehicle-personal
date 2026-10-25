"use client";

import { useState } from "react";
import { Save } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import type { PermissionT } from "@/lib/types";
import { Button, Card, ErrorText, Loading, PageHeader } from "@/components/app-ui";

type RoleRow = { role: string; label: string; permissions: string[] };

export default function RolesPage() {
  const roles = useFetch<RoleRow[]>("/roles");
  const perms = useFetch<PermissionT[]>("/permissions");
  const [active, setActive] = useState("admin");
  const [selected, setSelected] = useState<Record<string, Set<string>>>({});
  const [saving, setSaving] = useState(false);

  // start from what is saved each time the roles (re)load
  const [loadedFrom, setLoadedFrom] = useState<RoleRow[] | null>(null);
  if (roles.data && roles.data !== loadedFrom) {
    setLoadedFrom(roles.data);
    setSelected(Object.fromEntries(roles.data.map((r) => [r.role, new Set(r.permissions)])));
  }

  if ((roles.loading || perms.loading) && !roles.data) return <Loading />;
  if (roles.error || perms.error || !roles.data || !perms.data) return <ErrorText message={roles.error ?? perms.error ?? "Failed to load"} />;

  const current = selected[active] ?? new Set<string>();
  const groups = perms.data.reduce<Record<string, PermissionT[]>>((acc, p) => ((acc[p.group] ??= []).push(p), acc), {});
  const original = new Set(roles.data.find((r) => r.role === active)?.permissions ?? []);
  const dirty = current.size !== original.size || [...current].some((k) => !original.has(k));

  const update = (fn: (s: Set<string>) => void) =>
    setSelected((prev) => {
      const next = new Set(prev[active]);
      fn(next);
      return { ...prev, [active]: next };
    });

  const toggle = (key: string) => update((s) => (s.has(key) ? s.delete(key) : s.add(key)));
  const toggleGroup = (items: PermissionT[], on: boolean) => update((s) => items.forEach((p) => (on ? s.add(p.key) : s.delete(p.key))));

  const save = async () => {
    setSaving(true);
    try {
      const res = await api(`/roles/${active}/permissions`, { method: "PUT", body: { permissions: [...current] } });
      toast.success(res.message);
      roles.reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <PageHeader title="Roles & permissions" actions={<Button disabled={!dirty || saving} onClick={save}><Save className="size-4" /> {saving ? "Saving..." : "Save changes"}</Button>} />
      <p className="mb-4 text-sm text-slate-600">Choose what each role can do in your shop. Some permissions are already selected as a starting point. Add or remove any of them. You (the shop owner) always have full access.</p>
      <div className="mb-4 flex gap-2">
        {roles.data.map((r) => (
          <Button key={r.role} variant={active === r.role ? "primary" : "outline"} onClick={() => setActive(r.role)}>
            {r.label} <span className="ml-1 rounded-full bg-white/25 px-2 text-xs">{selected[r.role]?.size ?? r.permissions.length}</span>
          </Button>
        ))}
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        {Object.entries(groups).map(([group, items]) => {
          const all = items.every((p) => current.has(p.key));
          return (
            <Card key={group} title={group.charAt(0).toUpperCase() + group.slice(1)} actions={<button type="button" className="text-xs font-medium text-emerald-700 underline" onClick={() => toggleGroup(items, !all)}>{all ? "Clear all" : "Select all"}</button>}>
              <ul className="space-y-2">
                {items.map((p) => (
                  <li key={p.id}>
                    <label className="flex cursor-pointer items-start gap-3 text-sm">
                      <input type="checkbox" className="mt-0.5 size-4 accent-emerald-600" checked={current.has(p.key)} onChange={() => toggle(p.key)} />
                      <span>
                        <span className="font-medium text-slate-800">{p.name}</span>
                        {p.description && <span className="block text-xs text-slate-500">{p.description}</span>}
                      </span>
                    </label>
                  </li>
                ))}
              </ul>
            </Card>
          );
        })}
      </div>
    </>
  );
}
