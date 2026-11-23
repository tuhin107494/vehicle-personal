"use client";

import { useState } from "react";
import { toast } from "sonner";
import { Merge, X } from "lucide-react";
import { api, ApiError } from "@/lib/api";
import type { Customer } from "@/lib/types";
import { Button, Modal } from "@/components/app-ui";
import { CustomerSelect } from "@/components/Selects";

type Picked = Pick<Customer, "id" | "name" | "phone" | "alt_phone" | "father_name">;

const line = (c: Picked) => [c.father_name ? `Father: ${c.father_name}` : "", [c.phone, c.alt_phone].filter(Boolean).join(" / ")].filter(Boolean).join(" · ");

/**
 * One person entered as more than one customer (often under another phone number): choose the main customer and its
 * duplicates. Every sale, payment, installment, buy-back and booking of the duplicates moves to the main customer, and the
 * duplicates are deleted.
 */
export function CustomerMergeDialog({ open, primary: fixedPrimary, onClose, onMerged }: { open: boolean; primary?: Picked; onClose: () => void; onMerged: (main: Customer) => void }) {
  const [primary, setPrimary] = useState<Picked | null>(fixedPrimary ?? null);
  const [duplicates, setDuplicates] = useState<Picked[]>([]);
  const [busy, setBusy] = useState(false);

  const load = async (id: string): Promise<Picked | null> => {
    if (!id) return null;
    try {
      return (await api<Customer>(`/customers/${id}`)).data;
    } catch {
      toast.error("Could not load that customer.");
      return null;
    }
  };

  const addDuplicate = async (id: string) => {
    const c = await load(id);
    if (!c) return;
    if (c.id === primary?.id) return void toast.error("That is the main customer.");
    setDuplicates((list) => (list.some((d) => d.id === c.id) ? list : [...list, c]));
  };

  const merge = async () => {
    if (!primary || !duplicates.length) return;
    if (!confirm(`Merge ${duplicates.length} duplicate${duplicates.length > 1 ? "s" : ""} into ${primary.name}? Their sales and payments move to ${primary.name}, and the duplicates are deleted.`)) return;
    setBusy(true);
    try {
      const res = await api<Customer>(`/customers/${primary.id}/merge`, { method: "POST", body: { duplicate_ids: duplicates.map((d) => d.id) } });
      toast.success("Customers merged successfully");
      onMerged(res.data);
      onClose();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open={open} title="Merge customers" onClose={onClose}>
      <div className="space-y-4">
        <p className="text-sm text-slate-600">For one person entered more than once. Every sale, payment and installment of the duplicates moves to the main customer, and the duplicates are deleted. Details the main customer is missing (father&apos;s name, NID, address, a second number) are taken from the duplicates.</p>

        {fixedPrimary ? (
          <div className="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm">
            <p className="text-xs font-medium uppercase text-emerald-700">Main customer (kept)</p>
            <p className="font-semibold">{fixedPrimary.name}</p>
            <p className="text-xs text-slate-500">{line(fixedPrimary)}</p>
          </div>
        ) : (
          <CustomerSelect allowCreate={false} label="Main customer (kept)" value={primary ? String(primary.id) : ""} selectedLabel={primary?.name}
            onChange={async (id) => { const c = await load(id); setPrimary(c); if (c) setDuplicates((list) => list.filter((d) => d.id !== c.id)); }} />
        )}

        {primary && (
          <div>
            {/* a fresh picker after each choice, so the next duplicate can be searched straight away */}
            <CustomerSelect key={duplicates.length} allowCreate={false} optional label="Add a duplicate to merge" value="" onChange={addDuplicate} />
            <ul className="mt-2 space-y-2">
              {duplicates.map((d) => (
                <li key={d.id} className="flex items-center justify-between gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm">
                  <div className="min-w-0">
                    <p className="font-medium">{d.name}</p>
                    <p className="text-xs text-slate-500">{line(d)}</p>
                  </div>
                  <button type="button" aria-label="Remove" title="Remove" onClick={() => setDuplicates((list) => list.filter((x) => x.id !== d.id))} className="flex size-6 shrink-0 items-center justify-center rounded-full text-slate-400 hover:bg-white hover:text-red-600"><X className="size-4" /></button>
                </li>
              ))}
              {!duplicates.length && <li className="text-xs text-slate-500">No duplicate chosen yet.</li>}
            </ul>
          </div>
        )}

        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button disabled={busy || !primary || !duplicates.length} onClick={merge}><Merge className="size-4" /> {busy ? "Merging..." : `Merge${duplicates.length ? ` ${duplicates.length}` : ""} into main`}</Button>
        </div>
      </div>
    </Modal>
  );
}
