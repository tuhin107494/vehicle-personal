"use client";

import { Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import type { DraftT } from "@/lib/types";
import { Button } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";

/**
 * A saved draft comes back from the server with empty fields as null, which the form cannot show.
 * Turn them back into what the form expects, using the form's current values as the shape.
 */
export function draftValues<T extends object>(saved: Record<string, unknown> | undefined, shape: T): T {
  const fields: Record<string, unknown> = Object.fromEntries(Object.entries(shape));
  const out: Record<string, unknown> = { ...fields };
  for (const [key, value] of Object.entries(saved ?? {})) {
    out[key] = typeof fields[key] === "boolean" ? Boolean(value) : value ?? "";
  }
  return out as T;
}

/** The table of saved half-finished purchases (the page's Drafts tab). "Resume" hands the saved form back to the page. */
export function DraftsBar({ drafts, loading, activeId, onResume, onChanged }: {
  drafts: DraftT[];
  loading?: boolean;
  activeId: number | null;
  onResume: (draft: DraftT) => void;
  onChanged: (deletedId: number) => void;
}) {
  const remove = async (d: DraftT) => {
    if (!confirm("Delete this draft?")) return;
    try {
      await api(`/drafts/${d.id}`, { method: "DELETE" });
      toast.success("Draft deleted");
      onChanged(d.id);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed to delete");
    }
  };

  const columns: Col<DraftT>[] = [
    { header: "Draft", cell: ({ row }) => <span className="font-medium">{row.original.label || `Draft #${row.original.id}`}{activeId === row.original.id && <span className="ml-2 text-xs font-normal text-emerald-700">(open)</span>}</span> },
    { header: "Saved", cell: ({ row }) => <span className="whitespace-nowrap text-sm text-slate-600">{new Date(row.original.updated_at).toLocaleString("en-GB", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" })}{row.original.saved_by ? ` · ${row.original.saved_by}` : ""}</span> },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => (
        <div className="flex items-center justify-end gap-1.5">
          <Button variant="outline" onClick={() => onResume(row.original)}>Resume</Button>
          <button type="button" aria-label="Delete draft" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700" onClick={() => remove(row.original)}><Trash2 className="size-4" /></button>
        </div>
      ),
    },
  ];

  return <DataTable columns={columns} data={drafts} loading={loading} pageSize={10} empty='No drafts saved. Use "Save as draft" on the purchase form to keep a half-finished purchase here.' />;
}
