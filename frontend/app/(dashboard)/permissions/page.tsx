"use client";

import { savedMsg } from "@/lib/format";
import { useEffect, useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, ApiError, applyServerErrors } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import type { PermissionT } from "@/lib/types";
import { Button, Card, ErrorText, Field, inputCls, Loading, Modal, PageHeader } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const schema = z.object({
  key: z.string(),
  name: z.string().min(1, "Permission name is required."),
  group: z.string().min(1, "Group is required."),
  description: z.string(),
});
type Values = z.infer<typeof schema>;

function PermissionForm({ open, permission, onClose, onSaved }: { open: boolean; permission: PermissionT | null; onClose: () => void; onSaved: () => void }) {
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { key: "", name: "", group: "", description: "" } });

  useEffect(() => {
    if (open) reset(permission ? { key: permission.key, name: permission.name, group: permission.group, description: permission.description ?? "" } : { key: "", name: "", group: "", description: "" });
  }, [open, permission, reset]);

  const onSubmit = async (v: Values) => {
    if (!permission && !/^[a-z_]+\.[a-z_]+$/.test(v.key)) {
      setError("key", { message: "Use the format group.action, for example reports.export." });
      return;
    }
    try {
      await api(permission ? `/permissions/${permission.id}` : "/permissions", {
        method: permission ? "PUT" : "POST",
        body: { name: v.name, group: v.group, description: v.description || null, ...(permission ? {} : { key: v.key }) },
      });
      toast.success(savedMsg("Permission", !!permission));
      onSaved();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={permission ? "Edit permission" : "New permission"}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
        <Field required={!permission} label="Key (group.action)" error={errors.key?.message}><Input className={inputCls} disabled={!!permission} placeholder="reports.export" {...register("key")} /></Field>
        <Field required label="Name" error={errors.name?.message}><Input className={inputCls} {...register("name")} /></Field>
        <Field required label="Group" error={errors.group?.message}><Input className={inputCls} placeholder="reports" {...register("group")} /></Field>
        <Field label="Description" error={errors.description?.message}><Textarea rows={2} className={inputCls} {...register("description")} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save"}</Button>
        </div>
      </form>
    </Modal>
  );
}

export default function PermissionsPage() {
  const { data, loading, error, reload } = useFetch<PermissionT[]>("/permissions");
  const [editing, setEditing] = useState<PermissionT | null>(null);
  const [open, setOpen] = useState(false);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Failed to load"} />;

  const groups = data.reduce<Record<string, PermissionT[]>>((acc, p) => ((acc[p.group] ??= []).push(p), acc), {});

  const remove = async (p: PermissionT) => {
    if (!confirm(`Delete "${p.name}"? Every shop role loses it.`)) return;
    try {
      await api(`/permissions/${p.id}`, { method: "DELETE" });
      toast.success("Permission deleted successfully");
      reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    }
  };

  return (
    <>
      <PageHeader title="Permissions" actions={<Button onClick={() => { setEditing(null); setOpen(true); }}><Plus className="size-4" /> New permission</Button>} />
      <p className="mb-4 text-sm text-slate-600">Permissions you create here appear on every shop owner&apos;s Roles screen. Built-in permissions are enforced by the app; new ones are available for future features.</p>
      <div className="space-y-4">
        {Object.entries(groups).map(([group, items]) => (
          <Card key={group} title={group.charAt(0).toUpperCase() + group.slice(1)}>
            <ul className="divide-y divide-emerald-50">
              {items.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <div>
                    <p className="font-medium text-slate-800">{p.name}</p>
                    <p className="text-xs text-slate-500"><code>{p.key}</code>{p.description ? ` · ${p.description}` : ""}</p>
                  </div>
                  <div className="flex gap-2">
                    <Button variant="outline" onClick={() => { setEditing(p); setOpen(true); }}><Pencil className="size-4 text-sky-600" /> Edit</Button>
                    <Button variant="danger" onClick={() => remove(p)}><Trash2 className="size-4" /> Delete</Button>
                  </div>
                </li>
              ))}
            </ul>
          </Card>
        ))}
      </div>
      <PermissionForm open={open} permission={editing} onClose={() => setOpen(false)} onSaved={reload} />
    </>
  );
}
