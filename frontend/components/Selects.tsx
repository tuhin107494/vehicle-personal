"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useFetch } from "@/lib/hooks";
import type { Investor, Party } from "@/lib/types";
import { Button, Field } from "@/components/app-ui";
import { PartyForm } from "@/components/forms/PartyForm";
import { SearchSelect } from "@/components/SearchSelect";

export function PartySelect({ label, value, onChange, error, type, allowCreate = true, selectedLabel, filter }: { label: string; value: string; onChange: (id: string) => void; error?: string; type?: "customer" | "supplier"; allowCreate?: boolean; selectedLabel?: string; filter?: boolean }) {
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");
  const [creating, setCreating] = useState(false);
  const [typed, setTyped] = useState(""); // what was typed in the search when "+ Add new" was chosen

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search), 250);
    return () => clearTimeout(t);
  }, [search]);

  const list = useFetch<Party[]>(`/parties?per_page=30${type ? `&type=${type}` : ""}${debounced ? `&search=${encodeURIComponent(debounced)}` : ""}`);
  const options = (list.data ?? []).map((p) => ({ value: String(p.id), label: p.name, hint: [[p.phone, p.alt_phone].filter(Boolean).join(" / "), p.nid ? `NID ${p.nid}` : ""].filter(Boolean).join(" · ") }));

  return (
    <Field required={!filter} label={label} error={error}>
      <div className="flex gap-2">
        <SearchSelect options={options} value={value} onChange={(id) => onChange(id)} onQueryChange={setSearch} loading={list.loading} fallbackLabel={selectedLabel} placeholder="Search by name or phone..."
          onCreate={allowCreate ? (text) => { setTyped(text); setCreating(true); } : undefined} createLabel={type === "supplier" ? "Add new supplier" : "Add new customer"} />
        {allowCreate && <Button variant="outline" onClick={() => { setTyped(""); setCreating(true); }}>+ New</Button>}
      </div>
      {!allowCreate && !filter && (
        <p className="mt-1 hidden text-xs text-slate-500 md:block">
          Not listed? Add it on the <Link className="font-medium text-orange-600 underline hover:text-orange-700" href={type === "supplier" ? "/suppliers" : "/customers"}>{type === "supplier" ? "Suppliers" : "Customers"}</Link> page.
        </p>
      )}
      <PartyForm open={creating} initialText={typed} kind={type === "supplier" ? "supplier" : "customer"} onClose={() => setCreating(false)} onSaved={(p) => { setSearch(""); list.reload(); onChange(String(p.id)); }} />
    </Field>
  );
}

export function InvestorSelect({ value, onChange, error, optional, filter }: { value: string; onChange: (id: string) => void; error?: string; optional?: boolean; filter?: boolean }) {
  const list = useFetch<Investor[]>("/investors?per_page=100&is_active=1");
  const options = (list.data ?? []).map((i) => ({ value: String(i.id), label: i.name, hint: [i.phone, i.alt_phone].filter(Boolean).join(" / ") }));

  return (
    <Field required={!optional && !filter} label={filter ? "Investor" : optional ? "Investor (optional)" : "Investor"} error={error}>
      <SearchSelect options={options} value={value} onChange={(id) => onChange(id)} placeholder={filter ? "All investors" : optional ? "No investor - shop finances it" : "Search investor..."} />
      {(optional || filter) && !filter && value && <button type="button" className="mt-1 text-xs font-medium text-emerald-700 hover:underline" onClick={() => onChange("")}>Remove investor</button>}
    </Field>
  );
}
