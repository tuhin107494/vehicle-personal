"use client";

import { useState } from "react";
import { ChevronDown, ChevronUp, FileClock, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import type { DraftT } from "@/lib/types";
import { Button, Pagination } from "@/components/app-ui";
import { usePaging } from "@/lib/paging";

/**
 * A saved draft comes back from the server with empty fields as null, which the form cannot show.
 * Turn them back into what the form expects, using the form's current values as the shape.
 */
export function draftValues<T extends Record<string, any>>(saved: Record<string, any>, shape: T): T {
  const out: Record<string, any> = { ...shape };
  for (const [key, value] of Object.entries(saved ?? {})) {
    out[key] = typeof shape[key] === "boolean" ? Boolean(value) : value ?? "";
  }
  return out as T;
}

/** Saved half-finished purchases or sales. "Resume" hands the saved form back to the page. */
export function DraftsBar({ type, refreshKey, activeId, onResume, onDeleted }: {
  type: "purchase" | "sale";
  refreshKey: number;
  activeId: number | null;
  onResume: (draft: DraftT) => void;
  onDeleted: (id: number) => void;
}) {
  const [open, setOpen] = useState(false);
  const { data, reload } = useFetch<DraftT[]>(`/drafts?type=${type}&k=${refreshKey}`);
  const drafts = data ?? [];

  const pg = usePaging(drafts, false, 5);

  if (!drafts.length) return null;

  const remove = async (d: DraftT) => {
    if (!confirm("Delete this draft?")) return;
    try {
      await api(`/drafts/${d.id}`, { method: "DELETE" });
      toast.success("Draft deleted");
      onDeleted(d.id);
      reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed to delete");
    }
  };

  return (
    <div className="mb-4 rounded-xl bg-white ring-1 ring-emerald-300">
      <button type="button" aria-expanded={open} onClick={() => setOpen((o) => !o)} className="flex w-full items-center justify-between px-4 py-2.5 text-sm font-medium text-emerald-800">
        <span className="flex items-center gap-2"><FileClock className="size-4" /> Drafts <span className="rounded-full bg-emerald-100 px-2 text-xs">{drafts.length}</span></span>
        {open ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
      </button>
      {open && (
        <ul className="divide-y divide-emerald-50 border-t border-emerald-100">
          {pg.items.map((d) => (
            <li key={d.id} className={`flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 ${activeId === d.id ? "bg-emerald-50" : ""}`}>
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-slate-800">{d.label || `Draft #${d.id}`}{activeId === d.id && <span className="ml-2 text-xs font-normal text-emerald-700">(open)</span>}</p>
                <p className="text-xs text-slate-500">Saved {new Date(d.updated_at).toLocaleString("en-GB", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" })}{d.saved_by ? ` by ${d.saved_by}` : ""}</p>
              </div>
              <div className="flex gap-2">
                <Button variant="outline" onClick={() => { onResume(d); setOpen(false); }}>Resume</Button>
                <button type="button" aria-label="Delete draft" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700" onClick={() => remove(d)}><Trash2 className="size-4" /></button>
              </div>
            </li>
          ))}
        </ul>
      )}
      {open && <div className="px-4 pb-3"><Pagination meta={pg.meta} onPage={pg.setPage} /></div>}
    </div>
  );
}
