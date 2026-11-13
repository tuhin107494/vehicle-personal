"use client";

import { useState } from "react";
import { useParams, useSearchParams } from "next/navigation";
import { Copy, Plug, Save, Upload } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import type { CredentialFieldT, GatewayConfigT, GatewayLogT, GatewayMode } from "@/lib/types";
import { Badge, Button, Card, ErrorText, Field, inputCls, Loading, Modal, PageHeader, Pagination } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { NativeSelect } from "@/components/ui/native-select";
import { DataTable, type Col } from "@/components/DataTable";
import { GatewayCredentialInput } from "@/components/GatewayCredentialInput";

type General = { display_name: string; description: string; min_amount: string; max_amount: string; fee_percent: string; fee_fixed: string; webhook_ips: string; sort_order: string };
type Creds = Record<string, string>;

const generalFrom = (g: GatewayConfigT): General => ({
  display_name: g.display_name, description: g.description ?? "", min_amount: g.min_amount_paisa === null ? "" : String(g.min_amount_paisa / 100),
  max_amount: g.max_amount_paisa === null ? "" : String(g.max_amount_paisa / 100), fee_percent: String(g.fee_percent), fee_fixed: String(g.fee_fixed_paisa / 100),
  webhook_ips: g.webhook_ips ?? "", sort_order: String(g.sort_order),
});

// secrets always start blank (blank keeps the saved one); other fields start with what is saved
const credsFrom = (schema: CredentialFieldT[], saved: Record<string, string | boolean | null>): Creds =>
  Object.fromEntries(schema.map((f) => [f.name, f.is_secret ? "" : f.type === "boolean" ? (saved[f.name] ? "1" : "0") : String(saved[f.name] ?? "")]));

const toTaka = (v: string) => (v.trim() === "" ? null : Math.round(Number(v) * 100));

type Theme = { title: string; primary: string; outline: string; tab: string; ring: string; check: string; upload: string };

/** The app's own green, for a gateway with no brand colours of its own. */
const APP_THEME: Theme = {
  title: "", primary: "", outline: "", tab: "text-emerald-700", ring: "", check: "accent-emerald-600",
  upload: "border-emerald-200 text-emerald-700 hover:bg-emerald-50",
};

/** Brand colours per gateway. bKash pink #E2136E is 4.6:1 against white, so it passes for text and for white text on it. */
const THEMES: Record<string, Theme> = {
  bkash: {
    title: "text-[#E2136E]",
    primary: "from-[#E2136E] to-[#C8105F] hover:from-[#C8105F] hover:to-[#AD0E52]",
    outline: "border-pink-200 text-[#E2136E] hover:bg-pink-50 hover:text-[#AD0E52]",
    tab: "text-[#E2136E]",
    ring: "ring-pink-200",
    check: "accent-[#E2136E]",
    upload: "border-pink-200 text-[#E2136E] hover:bg-pink-50",
  },
};

export default function ConfigureGatewayPage() {
  const { code } = useParams<{ code: string }>();
  const params = useSearchParams();
  const [tab, setTab] = useState<"settings" | "logs">(params.get("tab") === "logs" ? "logs" : "settings");
  const gateway = useFetch<GatewayConfigT>(`/admin/gateways/${code}`);
  const g = gateway.data;

  const [general, setGeneral] = useState<General | null>(null);
  const [mode, setMode] = useState<GatewayMode>("sandbox");
  const [active, setActive] = useState(false);
  const [creds, setCreds] = useState<{ sandbox: Creds; live: Creds }>({ sandbox: {}, live: {} });
  const [editing, setEditing] = useState<GatewayMode>("sandbox");
  const [password, setPassword] = useState("");
  const [askPassword, setAskPassword] = useState(false);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);

  // the form starts from what is saved each time the gateway is (re)loaded
  const [loadedFor, setLoadedFor] = useState<GatewayConfigT | null>(null);
  if (g?.details && g !== loadedFor) {
    setLoadedFor(g);
    setGeneral(generalFrom(g));
    setMode(g.active_mode);
    setEditing(g.active_mode);
    setActive(g.is_active);
    setCreds({ sandbox: credsFrom(g.details.schema, g.details.sandbox), live: credsFrom(g.details.schema, g.details.live) });
  }

  if (gateway.error) return <ErrorText message={gateway.error} />;
  if (!g?.details || !general) return <Loading />;
  const details = g.details;

  const submit = async (pwd?: string) => {
    // only the credential fields that were typed into go up: blank secrets stay as they are on the server
    const sent = (m: GatewayMode) => Object.fromEntries(Object.entries(creds[m]).filter(([name, v]) => v !== "" || !details.schema.find((f) => f.name === name)?.is_secret));
    const body = {
      general: {
        display_name: general.display_name, description: general.description || null, is_active: active, sort_order: Number(general.sort_order || 0), active_mode: mode,
        min_amount_paisa: toTaka(general.min_amount), max_amount_paisa: toTaka(general.max_amount), fee_percent: Number(general.fee_percent || 0), fee_fixed_paisa: toTaka(general.fee_fixed) ?? 0,
        webhook_ips: general.webhook_ips || null,
      },
      sandbox: sent("sandbox"), live: sent("live"), ...(pwd ? { password: pwd } : {}),
    };
    setSaving(true);
    try {
      const res = await api<GatewayConfigT>(`/admin/gateways/${code}`, { method: "PUT", body });
      toast.success(res.message);
      setAskPassword(false);
      setPassword("");
      gateway.reload();
    } catch (e) {
      const msg = e instanceof ApiError ? e.message : "Failed to save";
      if (e instanceof ApiError && e.errors?.password) setAskPassword(true);
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  };

  const save = () => (mode === "live" && g.active_mode !== "live" ? setAskPassword(true) : submit());

  const test = async () => {
    setTesting(true);
    try {
      const res = await api<{ ok: boolean; error?: string }>(`/admin/gateways/${code}/test`, { method: "POST" });
      if (res.data.ok) toast.success(res.message);
      else toast.error(res.data.error ?? res.message);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setTesting(false);
    }
  };

  const uploadLogo = async (file: File | undefined) => {
    if (!file) return;
    const fd = new FormData();
    fd.append("photo", file);
    try {
      const res = await api(`/admin/gateways/${code}/logo`, { method: "POST", body: fd });
      toast.success(res.message);
      gateway.reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "The logo could not be uploaded.");
    }
  };

  const copy = (value: string) => navigator.clipboard.writeText(value).then(() => toast.success("Copied."), () => toast.error("Could not copy."));
  const th = THEMES[g.code] ?? APP_THEME;
  const setG = (k: keyof General) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setGeneral((s) => (s ? { ...s, [k]: e.target.value } : s));

  return (
    <>
      <PageHeader
        title={g.display_name}
        titleClass={th.title}
        back={false}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" className={th.outline} disabled={testing} onClick={test}><Plug className="size-4" /> {testing ? "Testing..." : "Test connection"}</Button>
            {tab === "settings" && <Button className={th.primary} disabled={saving} onClick={save}><Save className="size-4" /> {saving ? "Saving..." : "Save gateway"}</Button>}
          </div>
        }
      />
      <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <code className="rounded bg-slate-100 px-2 py-0.5 text-xs">{g.code}</code>
        <Badge tone={g.active_mode === "live" ? "green" : "yellow"}>{g.active_mode === "live" ? "Live" : "Sandbox"}</Badge>
        <Badge tone={details.configured[g.active_mode] ? "green" : "red"}>{details.configured[g.active_mode] ? "Configured" : "Not configured"}</Badge>
        <span className="ml-auto flex gap-1 rounded-lg bg-slate-100 p-1">
          {(["settings", "logs"] as const).map((t) => <button key={t} type="button" onClick={() => setTab(t)} className={`rounded-md px-3 py-1 text-sm font-medium capitalize ${tab === t ? `bg-white shadow-sm ${th.tab}` : "text-slate-600"}`}>{t}</button>)}
        </span>
      </div>

      {tab === "logs" ? <LogsTab code={code} /> : (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card title="General" className={th.ring}>
            <div className="space-y-3">
              <Field required label="Display name"><Input className={inputCls} value={general.display_name} onChange={setG("display_name")} /></Field>
              <Field label="Shown at checkout"><Textarea className={inputCls} rows={2} value={general.description} onChange={setG("description")} /></Field>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Minimum amount (taka)"><Input type="number" min={0} step="0.01" className={inputCls} value={general.min_amount} onChange={setG("min_amount")} /></Field>
                <Field label="Maximum amount (taka)"><Input type="number" min={0} step="0.01" className={inputCls} value={general.max_amount} onChange={setG("max_amount")} /></Field>
                <Field label="Fee percent"><Input type="number" min={0} max={100} step="0.01" className={inputCls} value={general.fee_percent} onChange={setG("fee_percent")} /></Field>
                <Field label="Fixed fee (taka)"><Input type="number" min={0} step="0.01" className={inputCls} value={general.fee_fixed} onChange={setG("fee_fixed")} /></Field>
                <Field label="Sort order"><Input type="number" min={0} className={inputCls} value={general.sort_order} onChange={setG("sort_order")} /></Field>
                <Field label="Active mode">
                  <NativeSelect className={inputCls} value={mode} onChange={(e) => setMode(e.target.value as GatewayMode)}>
                    <option value="sandbox">Sandbox</option>
                    <option value="live">Live</option>
                  </NativeSelect>
                </Field>
              </div>
              <Field label="Webhook IPs (comma separated)"><Input className={inputCls} placeholder="e.g. 103.1.2.3, 103.1.2.4 (blank = any)" value={general.webhook_ips} onChange={setG("webhook_ips")} /></Field>
              <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700"><input type="checkbox" className={`size-4 ${th.check}`} checked={active} onChange={(e) => setActive(e.target.checked)} /> Active (offered at checkout)</label>
              <div className="flex items-center gap-3 pt-1">
                {g.logo_url ? <img src={g.logo_url} alt="" className="size-12 rounded-lg object-contain ring-1 ring-slate-200" /> : <span className="flex size-12 items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-500">Logo</span>}
                <label className={`inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium ${th.upload}`}>
                  <Upload className="size-4" /> Upload logo
                  <input type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(e) => uploadLogo(e.target.files?.[0])} />
                </label>
              </div>
            </div>
          </Card>

          <Card
            className={th.ring}
            title={editing === "live" ? "Live credentials" : "Sandbox credentials"}
            actions={
              <span className="flex gap-1 rounded-lg bg-slate-100 p-1">
                {(["sandbox", "live"] as const).map((m) => <button key={m} type="button" onClick={() => setEditing(m)} className={`rounded-md px-3 py-1 text-sm font-medium ${editing === m ? `bg-white shadow-sm ${th.tab}` : "text-slate-600"}`}>{m === "live" ? "Live" : "Sandbox"}{details.configured[m] ? " ✓" : ""}</button>)}
              </span>
            }
          >
            <p className="mb-3 text-xs text-slate-500">Leave a secret blank to keep the saved one.</p>
            <div className="space-y-3">
              {details.schema.map((f) => (
                <GatewayCredentialInput key={`${editing}-${f.name}`} field={f} value={creds[editing][f.name] ?? ""} saved={details[editing][f.name] ?? null} onChange={(v) => setCreds((c) => ({ ...c, [editing]: { ...c[editing], [f.name]: v } }))} />
              ))}
            </div>
          </Card>

          <Card title="URLs for the merchant portal" className={`lg:col-span-2 ${th.ring}`}>
            <div className="grid gap-2 sm:grid-cols-2">
              {Object.entries(details.urls).map(([name, url]) => (
                <div key={name} className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
                  <span className="w-16 shrink-0 text-xs font-medium uppercase text-slate-500">{name}</span>
                  <code className="min-w-0 flex-1 truncate text-xs">{url}</code>
                  <Button variant="outline" size="sm" className={th.outline} aria-label={`Copy ${name} URL`} onClick={() => copy(url)}><Copy className="size-3.5" /></Button>
                </div>
              ))}
            </div>
          </Card>
        </div>
      )}

      <Modal open={askPassword} onClose={() => setAskPassword(false)} title="Switching to live mode: enter your password">
        <form onSubmit={(e) => { e.preventDefault(); submit(password); }} className="space-y-3">
          <p className="text-sm text-slate-600">Live mode moves real money. Confirm it is you.</p>
          <Field required label="Password"><Input type="password" className={inputCls} value={password} onChange={(e) => setPassword(e.target.value)} autoFocus /></Field>
          <div className="flex justify-end gap-2">
            <Button variant="outline" className={th.outline} onClick={() => setAskPassword(false)}>Cancel</Button>
            <Button type="submit" className={th.primary} disabled={saving || !password}>{saving ? "Saving..." : "Switch to live"}</Button>
          </div>
        </form>
      </Modal>
    </>
  );
}

function LogsTab({ code }: { code: string }) {
  const [page, setPage] = useState(1);
  const logs = useFetch<GatewayLogT[]>(`/admin/gateways/${code}/logs?page=${page}`);
  const [open, setOpen] = useState<GatewayLogT | null>(null);
  const columns: Col<GatewayLogT>[] = [
    { header: "When", cell: ({ row }) => new Date(row.original.created_at).toLocaleString() },
    { header: "Direction", cell: ({ row }) => <Badge tone={row.original.direction === "error" ? "red" : row.original.direction === "webhook" ? "blue" : "gray"}>{row.original.direction}</Badge> },
    { header: "Action", cell: ({ row }) => row.original.action },
    { header: "HTTP", cell: ({ row }) => row.original.http_status ?? "-" },
    { header: "Attempt", cell: ({ row }) => row.original.attempt_id ?? "-" },
    { header: "Shop", cell: ({ row }) => row.original.shop_id ?? "-" },
    { header: "IP", cell: ({ row }) => row.original.ip ?? "-" },
    { header: "Payload", id: "payload", cell: ({ row }) => <Button variant="outline" size="sm" onClick={() => setOpen(row.original)}>View</Button> },
  ];
  return (
    <>
      {logs.error && <ErrorText message={logs.error} />}
      <DataTable columns={columns} data={logs.data ?? []} loading={logs.loading} empty="No logs yet." />
      <Pagination meta={logs.meta} onPage={setPage} />
      <Modal open={!!open} onClose={() => setOpen(null)} title="Log payload" wide>
        <pre className="max-h-[60vh] overflow-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{JSON.stringify(open?.payload ?? null, null, 2)}</pre>
      </Modal>
    </>
  );
}
