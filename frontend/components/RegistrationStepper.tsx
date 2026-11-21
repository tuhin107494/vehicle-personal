"use client";

import { useState } from "react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { label } from "@/lib/format";
import type { Sale } from "@/lib/types";
import { Button } from "@/components/app-ui";

const STEPS = ["pending", "bank_chalan_done", "file_received", "file_processing", "registration_complete", "document_provided"];

/** Where a sale's registration stands, step by step, with buttons to move it on or back (shared by the sale and investor sale pages). */
export function RegistrationStepper({ sale, onChanged }: { sale: Sale; onChanged: () => void }) {
  const { can } = useAuth();
  const current = STEPS.indexOf(sale.registration_status);
  const [busy, setBusy] = useState(false);

  const move = async (to: string, back: boolean) => {
    const note = back ? prompt("Reason for moving back a step (required):") : prompt("Note (optional):");
    if (note === null || (back && !note.trim())) return;
    setBusy(true);
    try {
      const res = await api(`/sales/${sale.id}/registration`, { method: "PATCH", body: { status: to, note: note || undefined } });
      toast.success(res.message);
      onChanged();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <ol className="flex flex-wrap gap-2">
        {STEPS.map((s, i) => (
          <li key={s} className={`rounded-full border px-3 py-1 text-xs capitalize ${i < current ? "border-green-600 bg-green-50 text-green-800" : i === current ? "border-gray-900 bg-gray-900 text-white" : "border-gray-300 text-gray-500"}`}>
            {i + 1}. {label(s)}
          </li>
        ))}
      </ol>
      {sale.status === "active" && can("registration.update") && (
        <div className="mt-3 flex gap-2">
          {current > 0 && <Button variant="outline" disabled={busy} onClick={() => move(STEPS[current - 1], true)}>Back one step</Button>}
          {current < STEPS.length - 1 && <Button disabled={busy} onClick={() => move(STEPS[current + 1], false)}>Mark “{label(STEPS[current + 1])}”</Button>}
        </div>
      )}
    </>
  );
}
