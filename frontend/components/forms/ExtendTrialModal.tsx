"use client";

import { useState } from "react";
import { toast } from "sonner";
import { api, ApiError, applyServerErrors } from "@/lib/api";
import { fmtDate } from "@/lib/format";
import { trialState } from "@/lib/trial";
import type { Shop } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { NativeSelect } from "@/components/ui/native-select";

const CHOICES = [7, 14, 30, 90];

/** The super admin adds days to a shop's free trial. */
export function ExtendTrialModal({ shop, onClose, onSaved }: { shop: Shop | null; onClose: () => void; onSaved: () => void }) {
  const [days, setDays] = useState(7);
  const [busy, setBusy] = useState(false);

  const save = async () => {
    if (!shop) return;
    setBusy(true);
    try {
      const res = await api(`/shops/${shop.id}/extend-trial`, { method: "POST", body: { days } });
      toast.success(res.message);
      onSaved();
      onClose();
    } catch (e) {
      toast.error(e instanceof ApiError && !e.errors ? e.message : applyServerErrors(e, () => {}));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open={!!shop} onClose={onClose} title="Extend trial">
      <div className="space-y-3 text-sm">
        <p className="text-slate-600">{shop?.name}: trial {shop?.trial_ends_at && trialState(shop.trial_ends_at).ended ? "ended" : "ends"} on <b>{shop?.trial_ends_at ? fmtDate(shop.trial_ends_at) : "-"}</b>. Days are added from the end date, or from today if it has already ended.</p>
        <Field label="Add days"><NativeSelect className={inputCls} value={days} onChange={(e) => setDays(Number(e.target.value))}>{CHOICES.map((d) => <option key={d} value={d}>{d} days</option>)}</NativeSelect></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button disabled={busy} onClick={save}>{busy ? "Saving..." : "Extend"}</Button>
        </div>
      </div>
    </Modal>
  );
}
