"use client";

import { useState } from "react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { label } from "@/lib/format";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";

export const REGISTRATION_STEPS = ["pending", "bank_chalan_done", "file_received", "file_processing", "registration_complete", "document_provided"];

/** Move a sold vehicle's registration one step forward, or one step back with a reason. */
export function RegistrationModal({ saleId, status, title, open, onClose, onSaved }: { saleId: number | null; status: string; title: string; open: boolean; onClose: () => void; onSaved: () => void }) {
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const current = REGISTRATION_STEPS.indexOf(status);

  const move = async (to: string, back: boolean) => {
    if (back && !note.trim()) {
      setError("Write the reason for moving back.");
      return;
    }
    setBusy(true);
    try {
      const res = await api(`/sales/${saleId}/registration`, { method: "PATCH", body: { status: to, note: note.trim() || undefined } });
      toast.success(res.message);
      setNote("");
      setError("");
      onSaved();
      onClose();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open={open} title={title} onClose={onClose}>
      <ol className="mb-4 space-y-1.5">
        {REGISTRATION_STEPS.map((s, i) => (
          <li key={s} className={`flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm ${i < current ? "border-emerald-300 bg-emerald-50 text-emerald-800" : i === current ? "border-sky-400 bg-sky-50 font-semibold text-sky-800" : "border-slate-200 text-slate-500"}`}>
            <span className="w-5 text-xs">{i < current ? "✓" : i + 1}</span>
            <span className="capitalize">{label(s)}</span>
            {i === current && <span className="ml-auto text-xs">Now</span>}
          </li>
        ))}
      </ol>
      <Field label="Note (needed only to move back)" error={error}>
        <Input className={inputCls} value={note} onChange={(e) => { setNote(e.target.value); setError(""); }} />
      </Field>
      <div className="mt-4 flex flex-wrap justify-end gap-2">
        {current > 0 && <Button variant="outline" disabled={busy} onClick={() => move(REGISTRATION_STEPS[current - 1], true)}>Back to {label(REGISTRATION_STEPS[current - 1])}</Button>}
        {current >= 0 && current < REGISTRATION_STEPS.length - 1 && <Button disabled={busy} onClick={() => move(REGISTRATION_STEPS[current + 1], false)}>Mark “{label(REGISTRATION_STEPS[current + 1])}”</Button>}
        {current === REGISTRATION_STEPS.length - 1 && <span className="text-sm text-emerald-700">Registration is complete.</span>}
      </div>
    </Modal>
  );
}
