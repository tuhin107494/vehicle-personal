"use client";

import { useEffect, useMemo, useRef } from "react";
import { Camera, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Avatar } from "@/components/Avatar";
import { Button } from "@/components/app-ui";
import type { PhotoChange } from "@/lib/photo";

const MAX_BYTES = 2 * 1024 * 1024;

/** Choose, change or remove a person's photo. The file is uploaded by the form after the record is saved. */
export function PhotoField({ name, url, change, onChange }: { name: string; url?: string | null; change: PhotoChange; onChange: (c: PhotoChange) => void }) {
  const input = useRef<HTMLInputElement>(null);
  const preview = useMemo(() => (change instanceof File ? URL.createObjectURL(change) : null), [change]);
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);

  const shown = change === "remove" ? null : preview ?? url;

  const pick = (file?: File) => {
    if (!file) return;
    if (!/^image\/(jpe?g|png|webp)$/.test(file.type)) return void toast.error("Use a JPG, PNG or WebP image.");
    if (file.size > MAX_BYTES) return void toast.error("The photo must be 2 MB or smaller.");
    onChange(file);
  };

  return (
    <div className="flex items-center gap-4">
      <Avatar name={name || "?"} url={shown} size="lg" />
      <div className="space-y-2">
        <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => { pick(e.target.files?.[0]); e.target.value = ""; }} />
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" onClick={() => input.current?.click()}><Camera className="size-4 text-sky-600" /> {shown ? "Change photo" : "Add photo"}</Button>
          {shown && <Button type="button" variant="outline" onClick={() => onChange(change instanceof File ? null : "remove")}><Trash2 className="size-4 text-red-500" /> Remove</Button>}
        </div>
        <p className="text-xs text-slate-500">JPG, PNG or WebP, up to 2 MB.</p>
      </div>
    </div>
  );
}
