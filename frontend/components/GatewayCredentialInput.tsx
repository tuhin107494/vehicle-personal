"use client";

import type { CredentialFieldT } from "@/lib/types";
import { Field, inputCls } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { NativeSelect } from "@/components/ui/native-select";

/**
 * One credential input (API key, secret key, URL...), drawn from the gateway's own schema, so a new gateway needs no new form.
 * `saved` is the masked tail of a secret already stored: it shows as the placeholder, and a blank field keeps it.
 */
export function GatewayCredentialInput({ field, value, saved = null, onChange }: { field: CredentialFieldT; value: string; saved?: string | boolean | null; onChange: (v: string) => void }) {
  const placeholder = field.is_secret && saved ? String(saved) : undefined;
  if (field.type === "boolean") {
    return <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-emerald-600" checked={value === "1"} onChange={(e) => onChange(e.target.checked ? "1" : "0")} /> {field.label}</label>;
  }
  if (field.type === "select") {
    return (
      <Field required={field.required} label={field.label}>
        <NativeSelect className={inputCls} value={value} onChange={(e) => onChange(e.target.value)}>
          <option value="">Select</option>
          {Object.entries(field.options).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </NativeSelect>
      </Field>
    );
  }
  if (field.type === "textarea") {
    return (
      <Field required={field.required && !saved} label={field.label}>
        <Textarea className={`${inputCls} font-mono text-xs`} rows={5} value={value} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} spellCheck={false} />
        {field.help && <p className="mt-1 text-xs text-slate-500">{field.help}</p>}
      </Field>
    );
  }
  return (
    <Field required={field.required && !saved} label={field.label}>
      {/* "new-password" is what stops the browser filling in this app's own login: it ignores "off" next to a password field */}
      <Input type={field.type === "password" ? "password" : field.type === "url" ? "url" : "text"} className={inputCls} value={value} placeholder={placeholder} autoComplete={field.type === "password" ? "new-password" : "off"} name={`gateway-${field.name}`} data-1p-ignore data-lpignore="true" onChange={(e) => onChange(e.target.value)} />
      {field.help && <p className="mt-1 text-xs text-slate-500">{field.help}</p>}
    </Field>
  );
}
