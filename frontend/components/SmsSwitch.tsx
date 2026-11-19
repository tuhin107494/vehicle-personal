"use client";

import { useState } from "react";
import { MessageSquare, MessageSquareOff } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/app-ui";

/**
 * Turns SMS due alerts off or on for one customer (all their sales) or one sale, e.g. after a payment plan is agreed.
 * Only users who may manage SMS see it. The new state is kept here: nothing else on the page shows it, so the page is not fetched again.
 */
export function SmsSwitch({ path, enabled, what }: { path: string; enabled: boolean | undefined; what: "customer" | "sale" }) {
  const { can } = useAuth();
  const [busy, setBusy] = useState(false);
  const [changed, setChanged] = useState<boolean | null>(null); // what was just saved
  const on = changed ?? enabled !== false;

  if (!can("sms.manage")) return null;

  const toggle = async () => {
    if (on && !confirm(`Stop SMS due alerts for this ${what}? They can be turned on again later.`)) return;
    setBusy(true);
    try {
      const res = await api<{ sms_enabled: boolean }>(path, { method: "PATCH", body: { sms_enabled: !on } });
      setChanged(res.data.sms_enabled);
      toast.success(res.message);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Button variant="outline" disabled={busy} onClick={toggle} title={on ? "Due alerts by SMS are on" : "Due alerts by SMS are off"}>
      {on ? <><MessageSquare className="size-4 text-emerald-600" /> SMS reminders on</> : <><MessageSquareOff className="size-4 text-rose-600" /> SMS reminders off</>}
    </Button>
  );
}
