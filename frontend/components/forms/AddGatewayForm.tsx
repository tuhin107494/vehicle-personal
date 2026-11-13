"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useT } from "@/lib/i18n";
import type { CredentialFieldT, GatewayConfigT, GatewayMode } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { NativeSelect } from "@/components/ui/native-select";
import { GatewayCredentialInput } from "@/components/GatewayCredentialInput";

/** A gateway the app supports but the platform has not added yet (GET /admin/gateways → meta.addable). */
export type AddableGateway = { code: string; schema: CredentialFieldT[] };

/** The name shops see first; the super admin can change it. */
const DEFAULT_NAMES: Record<string, string> = { bkash: "bKash", manual: "Bank transfer" };
const nameFor = (code: string) => DEFAULT_NAMES[code] ?? code.charAt(0).toUpperCase() + code.slice(1);

const blankCreds = (schema: CredentialFieldT[]) => Object.fromEntries(schema.map((f) => [f.name, f.type === "boolean" ? "0" : ""]));

/**
 * Add a payment gateway: pick one the app supports, name it, and enter its keys (API key, secret key and the rest its
 * schema asks for). It is saved switched off and in sandbox mode; the keys are stored encrypted and never shown again.
 */
export function AddGatewayForm({ open, addable, onClose, onSaved }: { open: boolean; addable: AddableGateway[]; onClose: () => void; onSaved: () => void }) {
  const { t } = useT();
  const router = useRouter();
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [mode, setMode] = useState<GatewayMode>("sandbox");
  const [creds, setCreds] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  // each time the form opens it starts on the first gateway that can still be added
  const [openedFor, setOpenedFor] = useState(false);
  if (openedFor !== open) {
    setOpenedFor(open);
    if (open) {
      const first = addable[0];
      setCode(first?.code ?? "");
      setName(first ? nameFor(first.code) : "");
      setDescription("");
      setMode("sandbox");
      setCreds(first ? blankCreds(first.schema) : {});
      setErrors({});
    }
  }

  const schema = addable.find((g) => g.code === code)?.schema ?? [];

  const pick = (next: string) => {
    const g = addable.find((a) => a.code === next);
    setCode(next);
    setName(nameFor(next));
    setCreds(g ? blankCreds(g.schema) : {});
    setErrors({});
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    // blank fields are left out, so an optional one stays unset
    const filled = Object.fromEntries(Object.entries(creds).filter(([, v]) => v !== ""));
    setBusy(true);
    try {
      const res = await api<GatewayConfigT>("/admin/gateways", { method: "POST", body: { code, general: { display_name: name, description: description || null }, [mode]: filled } });
      toast.success(res.message);
      onSaved();
      onClose();
      router.push(`/platform-gateways/${res.data.code}`);
    } catch (e) {
      if (e instanceof ApiError && e.errors) setErrors(e.errors);
      toast.error(e instanceof ApiError ? e.message : "Failed to save");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="Add payment gateway" wide>
      {addable.length === 0 ? (
        <p className="text-sm text-slate-600">{t("Every gateway the app supports is already added. Open Configure on a gateway to change its keys.")}</p>
      ) : (
        <form onSubmit={submit} className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field required label="Gateway" error={errors.code?.[0]}>
              <NativeSelect className={inputCls} value={code} onChange={(e) => pick(e.target.value)}>
                {addable.map((g) => <option key={g.code} value={g.code}>{nameFor(g.code)}</option>)}
              </NativeSelect>
            </Field>
            <Field required label="Display name" error={errors["general.display_name"]?.[0]}><Input className={inputCls} value={name} onChange={(e) => setName(e.target.value)} /></Field>
          </div>
          <Field label="Shown at checkout" error={errors["general.description"]?.[0]}><Textarea className={inputCls} rows={2} value={description} onChange={(e) => setDescription(e.target.value)} /></Field>

          <fieldset className="rounded-lg border border-emerald-200 p-3">
            <legend className="px-1 text-sm font-medium text-emerald-700">{t("Keys")}</legend>
            <div className="mb-3 flex items-center gap-2 text-sm">
              <span className="text-slate-600">{t("These keys are for")}</span>
              <span className="flex gap-1 rounded-lg bg-slate-100 p-1" role="group" aria-label={t("Keys for")}>
                {(["sandbox", "live"] as const).map((m) => <button key={m} type="button" aria-pressed={mode === m} onClick={() => setMode(m)} className={`rounded-md px-3 py-1 text-sm font-medium ${mode === m ? "bg-white text-emerald-700 shadow-sm" : "text-slate-600"}`}>{t(m === "live" ? "Live" : "Sandbox")}</button>)}
              </span>
            </div>
            <div className="space-y-3">
              {schema.map((f) => <GatewayCredentialInput key={`${code}-${f.name}`} field={f} value={creds[f.name] ?? ""} onChange={(v) => setCreds((c) => ({ ...c, [f.name]: v }))} />)}
            </div>
            <p className="mt-3 text-xs text-slate-500">{t("Keys are stored encrypted and are never shown again. The gateway starts switched off in sandbox mode: test the connection, then switch it on.")}</p>
          </fieldset>

          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={busy || !code || !name.trim()}>{busy ? "Saving..." : "Add gateway"}</Button>
          </div>
        </form>
      )}
    </Modal>
  );
}
