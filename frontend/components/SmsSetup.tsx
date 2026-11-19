"use client";

import { useState } from "react";
import { ChevronDown, Clock, Play, Save, Send } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import { type Settings, useSettings } from "@/lib/settings";
import { smsParts, STAGES } from "@/lib/sms";
import type { SmsTemplate } from "@/lib/types";
import { Badge, Button, Card, ErrorText, Field, inputCls, Loading } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";

type Sms = Settings["sms"];
const DEFAULTS: Sms = { enabled: false, language: "bn", days: { upcoming: 3, overdue: 1, final: 7 }, live: false, send_time: "10:00", send_from: 9, send_until: 20, last_run: null, last_count: null };
const pad = (h: number) => String(h).padStart(2, "0");
const hourLabel = (h: number) => `${h % 12 || 12} ${h < 12 ? "am" : "pm"}`;
const timeLabel = (t: string) => {
  const [h, m] = t.split(":").map(Number);
  return `${h % 12 || 12}:${pad(m)} ${h < 12 ? "am" : "pm"}`;
};
const HOURS = Array.from({ length: 18 }, (_, i) => i + 6); // 6 am to 11 pm
/** Send times every 30 minutes within the sending hours. */
const timesBetween = (from: number, until: number) => Array.from({ length: (until - from) * 2 }, (_, i) => `${pad(from + Math.floor(i / 2))}:${i % 2 ? "30" : "00"}`);
const DAYS = { upcoming: [1, 2, 3, 5, 7, 10, 15], overdue: [1, 2, 3, 5, 7, 10, 15], final: [2, 3, 5, 7, 10, 15, 20, 30, 45, 60] };
const plural = (d: number) => `${d} ${d === 1 ? "day" : "days"}`;

/**
 * The SMS page's Settings tab: three small cards (alerts on or off, which days, when to send). Each choice saves at once.
 */
export function SmsSettingsPanel() {
  const settings = useSettings();
  const [saved, setSaved] = useState<Partial<Sms>>({}); // what was just saved, until the settings are fetched again
  const [testPhone, setTestPhone] = useState("");
  const [testing, setTesting] = useState(false);
  const [running, setRunning] = useState(false);

  if (settings.loading && !settings.data) return <Loading />;
  if (settings.error || !settings.data) return <ErrorText message={settings.error ?? "Failed to load"} />;
  const current: Sms = { ...DEFAULTS, ...settings.data.sms, ...saved };

  const save = async (change: Partial<Sms>) => {
    try {
      await api("/settings", { method: "PUT", body: { sms: change } });
      setSaved((s) => ({ ...s, ...change, ...(change.days && { days: { ...current.days, ...change.days } }) }));
      toast.success("Saved");
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed to save");
    }
  };
  const setDays = (stage: keyof Sms["days"], value: number) => save({ days: { ...current.days, [stage]: value } });
  // a new window can leave the send time outside it: the time moves to the window's start in the same save
  const setHours = (from: number, until: number) => {
    const time = current.send_time < `${pad(from)}:00` || current.send_time >= `${pad(until)}:00` ? `${pad(from)}:00` : current.send_time;
    save({ send_from: from, send_until: until, send_time: time });
  };

  const runNow = async () => {
    if (!confirm("Send today's due alerts now? A customer who already got today's reminder does not get it again.")) return;
    setRunning(true);
    try {
      const res = await api<Sms>("/sms/run", { method: "POST" });
      setSaved((s) => ({ ...s, last_run: res.data.last_run, last_count: res.data.last_count }));
      toast.success(res.message);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setRunning(false);
    }
  };

  const sendTest = async () => {
    setTesting(true);
    try {
      const res = await api("/sms/test", { method: "POST", body: { phone: testPhone } });
      toast.success(res.message);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed to send");
    } finally {
      setTesting(false);
    }
  };

  return (
    <div className="space-y-4">
      {!current.live && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">No SMS gateway is set up on the server yet, so SMS are only written to the log, not sent.</p>}

      <Card title="Due alerts">
        <label className="flex cursor-pointer items-center gap-2 text-base font-medium text-slate-800">
          <input type="checkbox" className="size-5 accent-emerald-600" checked={current.enabled} onChange={(e) => save({ enabled: e.target.checked })} />
          Send customers an SMS about their dues
        </label>
        <p className="mt-1 text-xs text-slate-500">Before the due date, on the day and when it is overdue. A paid due gets no more SMS.</p>
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <Field label="Language">
            <NativeSelect className={inputCls} value={current.language} onChange={(e) => save({ language: e.target.value as Sms["language"] })}>
              <option value="bn">বাংলা (Bangla)</option>
              <option value="en">English</option>
            </NativeSelect>
          </Field>
          <Field label="Send a test SMS to">
            <div className="flex gap-2">
              <Input type="tel" className={inputCls} placeholder="01712345678" value={testPhone} onChange={(e) => setTestPhone(e.target.value)} />
              <Button variant="outline" disabled={testing || !testPhone.trim()} onClick={sendTest}><Send className="size-4" /> {testing ? "Sending..." : "Send"}</Button>
            </div>
          </Field>
        </div>
      </Card>

      <Card title="Which days">
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="First reminder">
            <NativeSelect className={inputCls} value={current.days.upcoming} onChange={(e) => setDays("upcoming", Number(e.target.value))}>
              {DAYS.upcoming.map((d) => <option key={d} value={d}>{plural(d)} before</option>)}
            </NativeSelect>
          </Field>
          <Field label="Overdue reminder">
            <NativeSelect className={inputCls} value={current.days.overdue} onChange={(e) => setDays("overdue", Number(e.target.value))}>
              {DAYS.overdue.filter((d) => d < current.days.final).map((d) => <option key={d} value={d}>{plural(d)} after</option>)}
            </NativeSelect>
          </Field>
          <Field label="Final reminder">
            <NativeSelect className={inputCls} value={current.days.final} onChange={(e) => setDays("final", Number(e.target.value))}>
              {DAYS.final.filter((d) => d > current.days.overdue).map((d) => <option key={d} value={d}>{plural(d)} after</option>)}
            </NativeSelect>
          </Field>
        </div>
        <p className="mt-2 text-xs text-slate-500">Plus one on the due date itself.</p>
      </Card>

      <Card title="When to send">
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Every day at">
            <NativeSelect className={inputCls} value={current.send_time} onChange={(e) => save({ send_time: e.target.value })}>
              {timesBetween(current.send_from, current.send_until).map((t) => <option key={t} value={t}>{timeLabel(t)}</option>)}
            </NativeSelect>
          </Field>
          <Field label="Only from">
            <NativeSelect className={inputCls} value={current.send_from} onChange={(e) => setHours(Number(e.target.value), current.send_until)}>
              {HOURS.filter((h) => h < current.send_until).map((h) => <option key={h} value={h}>{hourLabel(h)}</option>)}
            </NativeSelect>
          </Field>
          <Field label="Until">
            <NativeSelect className={inputCls} value={current.send_until} onChange={(e) => setHours(current.send_from, Number(e.target.value))}>
              {HOURS.filter((h) => h > current.send_from).map((h) => <option key={h} value={h}>{hourLabel(h)}</option>)}
            </NativeSelect>
          </Field>
        </div>
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3 text-sm text-slate-700">
          <span className="flex items-center gap-1.5"><Clock className="size-4 text-slate-500" />
            {current.last_run ? <>Last run: <b>{current.last_run}</b> · {current.last_count ?? 0} SMS</> : "Not run yet"}
          </span>
          <Button variant="outline" disabled={running || !current.enabled} onClick={runNow}><Play className="size-4 text-emerald-600" /> {running ? "Running..." : "Run now"}</Button>
        </div>
      </Card>
    </div>
  );
}

/** Shows how long a text is and how many SMS parts it takes; more than one part costs more. */
function Counter({ text }: { text: string }) {
  const { length, parts } = smsParts(text);
  return <p className={`mt-1 text-xs ${parts > 1 ? "font-medium text-amber-700" : "text-slate-500"}`}>{length} characters · {parts} SMS{parts > 1 ? ": shorten it to save cost" : ""}</p>;
}

/** One stage's text: a closed row showing the text, opened with a click to edit it. */
function TemplateCard({ template, onSaved }: { template: SmsTemplate; onSaved: (t: SmsTemplate) => void }) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState(template);
  const [saving, setSaving] = useState(false);
  const dirty = draft.body_bn !== template.body_bn || draft.body_en !== template.body_en || draft.is_active !== template.is_active;
  const label = STAGES.find((s) => s.stage === template.stage)?.label ?? template.key;

  const save = async () => {
    setSaving(true);
    try {
      const res = await api<SmsTemplate>(`/sms-templates/${template.key}`, { method: "PUT", body: { body_bn: draft.body_bn, body_en: draft.body_en, is_active: draft.is_active } });
      onSaved(res.data);
      setDraft(res.data);
      setOpen(false);
      toast.success(res.message);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed to save");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="rounded-xl bg-white shadow-sm ring-1 ring-emerald-200">
      <button type="button" aria-expanded={open} onClick={() => setOpen((v) => !v)} className="flex w-full items-center gap-3 px-4 py-3 text-left">
        <span className="min-w-0 flex-1">
          <span className="flex flex-wrap items-center gap-2 font-semibold text-slate-800">
            {label}
            <Badge tone={template.is_active ? "green" : "gray"}>{template.is_active ? "On" : "Off"}</Badge>
          </span>
          {!open && <span className="mt-0.5 block truncate text-sm text-slate-500">{template.body_bn}</span>}
        </span>
        <ChevronDown className={`size-5 shrink-0 text-slate-400 transition ${open ? "rotate-180" : ""}`} />
      </button>
      {open && (
        <div className="space-y-3 border-t border-emerald-100 px-4 py-3">
          <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="size-4 accent-emerald-600" checked={draft.is_active} onChange={(e) => setDraft({ ...draft, is_active: e.target.checked })} />
            Send this SMS {!template.edited && <Badge tone="gray">Built-in text</Badge>}
          </label>
          <div className={`grid gap-3 md:grid-cols-2 ${draft.is_active ? "" : "opacity-50"}`}>
            <Field label="Bangla">
              <Textarea rows={2} maxLength={480} className={inputCls} value={draft.body_bn} onChange={(e) => setDraft({ ...draft, body_bn: e.target.value })} />
              <Counter text={draft.body_bn} />
            </Field>
            <Field label="English">
              <Textarea rows={2} maxLength={480} className={inputCls} value={draft.body_en} onChange={(e) => setDraft({ ...draft, body_en: e.target.value })} />
              <Counter text={draft.body_en} />
            </Field>
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => { setDraft(template); setOpen(false); }}>Cancel</Button>
            <Button disabled={!dirty || saving} onClick={save}><Save className="size-4" /> {saving ? "Saving..." : "Save"}</Button>
          </div>
        </div>
      )}
    </div>
  );
}

/** The SMS page's Message texts tab: one card per stage. */
export function SmsTextsPanel() {
  const templates = useFetch<SmsTemplate[]>("/sms-templates");

  if (templates.loading && !templates.data) return <Loading />;
  if (templates.error) return <ErrorText message={templates.error} />;

  return (
    <div className="space-y-3">
      <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
        Click a message to see or edit it. You can use {"{name} {amount} {due_date} {ref} {office_phone} {shop}"} in the text. {"{office_phone}"} is your shop&apos;s phone number.
      </p>
      {(templates.data ?? []).map((t) => (
        <TemplateCard key={t.key} template={t} onSaved={(next) => templates.mutate((data, meta) => ({ data: (data ?? []).map((x) => (x.key === next.key ? next : x)), meta }))} />
      ))}
    </div>
  );
}
