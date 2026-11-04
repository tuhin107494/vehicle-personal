"use client";

import { useState } from "react";
import { Download, Upload } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { Button, Modal } from "@/components/app-ui";

type Result = { created: number; skipped_duplicates: number; invalid: number; errors: { row: number; message: string }[] };

const TEMPLATE = "name,father_name,phone,alt_phone,nid,address,notes\n";
const MAX_BYTES = 5 * 1024 * 1024;

function download(name: string, text: string) {
  const url = URL.createObjectURL(new Blob(["﻿" + text], { type: "text/csv;charset=utf-8" }));
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.click();
  URL.revokeObjectURL(url);
}

/** Bulk-add customers from a CSV file. Phones that already exist are skipped; bad rows are listed so they can be fixed and re-imported. */
export function CustomerImportDialog({ open, onClose, onImported }: { open: boolean; onClose: () => void; onImported: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<Result | null>(null);

  const close = () => {
    setFile(null);
    setResult(null);
    onClose();
  };

  const pick = (f: File | null) => {
    setResult(null);
    if (f && f.size > MAX_BYTES) {
      toast.error("The file must be 5 MB or smaller.");
      return setFile(null);
    }
    setFile(f);
  };

  const submit = async () => {
    if (!file) return;
    setBusy(true);
    try {
      const body = new FormData();
      body.append("file", file);
      const res = await api<Result>("/customers/import", { method: "POST", body });
      setResult(res.data);
      if (res.data.created > 0) onImported();
    } catch (e) {
      toast.error(e instanceof ApiError ? (e.errors ? Object.values(e.errors)[0]?.[0] : null) ?? e.message : "The import failed.");
    } finally {
      setBusy(false);
    }
  };

  const errorsCsv = (r: Result) => "row,problem\n" + r.errors.map((e) => `${e.row},"${e.message.replace(/"/g, '""')}"`).join("\n") + "\n";

  return (
    <Modal open={open} onClose={close} title="Import customers from CSV">
      <div className="space-y-3 text-sm">
        {!result ? (
          <>
            <p className="text-slate-600">Columns: <b>name</b> and <b>phone</b> are required; father_name, alt_phone, nid, address and notes are optional. Save the file as <b>CSV UTF-8</b>, with up to 2,000 customers. A mobile number that already exists is skipped.</p>
            <Button variant="outline" size="sm" onClick={() => download("customers-template.csv", TEMPLATE)}><Download className="size-4" /> Download template</Button>
            <input type="file" accept=".csv,text/csv" className="block w-full text-sm file:mr-3 file:rounded-lg file:border file:bg-white file:px-3 file:py-1.5" onChange={(e) => pick(e.target.files?.[0] ?? null)} />
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={close}>Cancel</Button>
              <Button disabled={!file || busy} onClick={submit}><Upload className="size-4" /> {busy ? "Importing…" : "Import"}</Button>
            </div>
          </>
        ) : (
          <>
            <ul className="space-y-1">
              <li className="text-emerald-700"><b>{result.created}</b> imported</li>
              <li className="text-slate-600"><b>{result.skipped_duplicates}</b> skipped (mobile number already exists)</li>
              <li className={result.invalid ? "text-red-600" : "text-slate-600"}><b>{result.invalid}</b> not imported (invalid)</li>
            </ul>
            {result.invalid > 0 && (
              <>
                <ul className="max-h-40 space-y-0.5 overflow-auto rounded-lg border p-2 text-xs text-red-700">
                  {result.errors.slice(0, 50).map((e) => <li key={e.row}>Row {e.row}: {e.message}</li>)}
                </ul>
                <Button variant="outline" size="sm" onClick={() => download("import-errors.csv", errorsCsv(result))}><Download className="size-4" /> Download problems</Button>
              </>
            )}
            <div className="flex justify-end"><Button onClick={close}>Done</Button></div>
          </>
        )}
      </div>
    </Modal>
  );
}
