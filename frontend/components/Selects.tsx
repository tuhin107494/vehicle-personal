"use client";

import { useState } from "react";
import Link from "next/link";
import { useDebounced, useFetch } from "@/lib/hooks";
import type { Customer, Investor, Supplier } from "@/lib/types";
import { Button, Field } from "@/components/app-ui";
import { CustomerForm } from "@/components/forms/CustomerForm";
import { SupplierForm } from "@/components/forms/SupplierForm";
import { SearchSelect } from "@/components/SearchSelect";

/** Search and pick a customer; `allowCreate` adds a "+ New" to add one on the spot, `filter` is the optional look used in report filters. */
export function CustomerSelect({ label = "Customer", value, onChange, error, allowCreate = true, selectedLabel, filter }: { label?: string; value: string; onChange: (id: string) => void; error?: string; allowCreate?: boolean; selectedLabel?: string; filter?: boolean }) {
  const [search, setSearch] = useState("");
  const [creating, setCreating] = useState(false);
  const [typed, setTyped] = useState(""); // what was typed in the search when "+ Add new" was chosen

  const debounced = useDebounced(search);

  const list = useFetch<Customer[]>(debounced || value ? `/customers?per_page=30${debounced ? `&search=${encodeURIComponent(debounced)}` : ""}` : null);
  const options = (list.data ?? []).map((p) => ({ value: String(p.id), label: p.name, hint: [[p.phone, p.alt_phone].filter(Boolean).join(" / "), p.nid ? `NID ${p.nid}` : ""].filter(Boolean).join(" · ") }));

  return (
    <Field required={!filter} label={label} error={error}>
      <div className="flex gap-2">
        <SearchSelect options={options} value={value} onChange={(id) => onChange(id)} onQueryChange={setSearch} minChars={1} loading={list.loading} fallbackLabel={selectedLabel} placeholder="Search by name or phone..."
          onCreate={allowCreate ? (text) => { setTyped(text); setCreating(true); } : undefined} createLabel="Add new customer" />
        {allowCreate && <Button variant="outline" onClick={() => { setTyped(""); setCreating(true); }}>+ New</Button>}
      </div>
      {!allowCreate && !filter && (
        <p className="mt-1 hidden text-xs text-slate-500 md:block">
          Not listed? Add it on the <Link className="font-medium text-orange-600 underline hover:text-orange-700" href="/customers">Customers</Link> page.
        </p>
      )}
      <CustomerForm open={creating} initialText={typed} onClose={() => setCreating(false)} onSaved={(p) => { setSearch(""); list.reload(); onChange(String(p.id)); }} />
    </Field>
  );
}

/** Search and pick a supplier; `allowCreate` adds a "+ New" to add one on the spot, `filter` is the optional look used in report filters. */
export function SupplierSelect({ label = "Supplier", value, onChange, error, allowCreate = true, selectedLabel, filter }: { label?: string; value: string; onChange: (id: string) => void; error?: string; allowCreate?: boolean; selectedLabel?: string; filter?: boolean }) {
  const [search, setSearch] = useState("");
  const [creating, setCreating] = useState(false);
  const [typed, setTyped] = useState(""); // what was typed in the search when "+ Add new" was chosen
  const [opened, setOpened] = useState(false); // the list is fetched when the field is first used (or when a value is already chosen and needs its name)

  const debounced = useDebounced(search);

  const list = useFetch<Supplier[]>(opened || value ? `/suppliers?per_page=30${debounced ? `&search=${encodeURIComponent(debounced)}` : ""}` : null);
  const options = (list.data ?? []).map((p) => ({ value: String(p.id), label: p.name, hint: [[p.phone, p.alt_phone].filter(Boolean).join(" / "), p.nid ? `NID ${p.nid}` : ""].filter(Boolean).join(" · ") }));

  return (
    <Field required={!filter} label={label} error={error}>
      <div className="flex gap-2">
        <SearchSelect options={options} value={value} onChange={(id) => onChange(id)} onQueryChange={setSearch} onOpen={() => setOpened(true)} loading={list.loading} fallbackLabel={selectedLabel} placeholder="Search by name or phone..."
          onCreate={allowCreate ? (text) => { setTyped(text); setCreating(true); } : undefined} createLabel="Add new supplier" />
        {allowCreate && <Button variant="outline" onClick={() => { setTyped(""); setCreating(true); }}>+ New</Button>}
      </div>
      {!allowCreate && !filter && (
        <p className="mt-1 hidden text-xs text-slate-500 md:block">
          Not listed? Add it on the <Link className="font-medium text-orange-600 underline hover:text-orange-700" href="/suppliers">Suppliers</Link> page.
        </p>
      )}
      <SupplierForm open={creating} initialText={typed} onClose={() => setCreating(false)} onSaved={(p) => { setSearch(""); list.reload(); onChange(String(p.id)); }} />
    </Field>
  );
}

export function InvestorSelect({ value, onChange, error, optional, filter }: { value: string; onChange: (id: string) => void; error?: string; optional?: boolean; filter?: boolean }) {
  const [opened, setOpened] = useState(false); // fetched when the field is first used, or when a value is already chosen and needs its name
  const list = useFetch<Investor[]>(opened || value ? "/investors?per_page=100&is_active=1" : null);
  const options = (list.data ?? []).map((i) => ({ value: String(i.id), label: i.name, hint: [i.phone, i.alt_phone].filter(Boolean).join(" / ") }));

  return (
    <Field required={!optional && !filter} label={filter ? "Investor" : optional ? "Investor (optional)" : "Investor"} error={error}>
      <SearchSelect options={options} value={value} onChange={(id) => onChange(id)} onOpen={() => setOpened(true)} loading={list.loading} placeholder={filter ? "All investors" : optional ? "No investor - shop finances it" : "Search investor..."} />
      {(optional || filter) && !filter && value && <button type="button" className="mt-1 text-xs font-medium text-emerald-700 hover:underline" onClick={() => onChange("")}>Remove investor</button>}
    </Field>
  );
}
