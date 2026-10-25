"use client";

import { savedMsg } from "@/lib/format";
import { useEffect, useState } from "react";
import { Pencil, Plus } from "lucide-react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import type { ShopUser } from "@/lib/types";
import { Badge, Button, ErrorText, Field, inputCls, Loading, Modal, PageHeader } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { Avatar } from "@/components/Avatar";
import { PhotoField } from "@/components/PhotoField";
import { savePhoto, type PhotoChange } from "@/lib/photo";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

const schema = z.object({
  name: z.string().min(1, "Name is required."),
  email: z.string().min(1, "Email is required.").email("Enter a valid email."),
  password: z.string(),
  role: z.enum(["admin", "moderator"]),
  is_active: z.boolean(),
});
type Values = z.infer<typeof schema>;
const blank: Values = { name: "", email: "", password: "", role: "moderator", is_active: true };

function UserForm({ open, user, onClose, onSaved }: { open: boolean; user: ShopUser | null; onClose: () => void; onSaved: () => void }) {
  const { register, handleSubmit, reset, setError, watch, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: blank });

  const [photo, setPhoto] = useState<PhotoChange>(null);

  useEffect(() => {
    if (open) setPhoto(null);
    if (open) reset(user ? { name: user.name, email: user.email, password: "", role: user.role as Values["role"], is_active: user.is_active } : blank);
  }, [open, user, reset]);

  const onSubmit = async (v: Values) => {
    if (!user && v.password.length < 8) return setError("password", { message: "Password must be at least 8 characters." });
    if (user && v.password && v.password.length < 8) return setError("password", { message: "Password must be at least 8 characters." });
    try {
      const res = await api<ShopUser>(user ? `/users/${user.id}` : "/users", { method: user ? "PUT" : "POST", body: { ...v, password: v.password || undefined } });
      try {
        await savePhoto(`/users/${res.data.id}`, photo);
      } catch {
        toast.error("The user was saved, but the photo could not be uploaded.");
      }
      toast.success(savedMsg("User", !!user));
      onSaved();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={user ? "Edit user" : "New user"}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
        <PhotoField name={watch("name")} url={user?.photo_url} change={photo} onChange={setPhoto} />
        <Field required label="Name" error={errors.name?.message}><Input className={inputCls} {...register("name")} /></Field>
        <Field required label="Email" error={errors.email?.message}><Input type="email" className={inputCls} {...register("email")} /></Field>
        <Field required={!user} label={user ? "New password (leave blank to keep)" : "Password"} error={errors.password?.message}><Input type="password" className={inputCls} {...register("password")} /></Field>
        <Field required label="Role" error={errors.role?.message}>
          <NativeSelect className={inputCls} {...register("role")}><option value="admin">Admin</option><option value="moderator">Moderator</option></NativeSelect>
        </Field>
        <label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" className="accent-emerald-600" {...register("is_active")} /> Active (can sign in)</label>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save"}</Button>
        </div>
      </form>
    </Modal>
  );
}

export default function UsersPage() {
  const { data, loading, error, reload } = useFetch<ShopUser[]>("/users");
  const [editing, setEditing] = useState<ShopUser | null>(null);
  const [open, setOpen] = useState(false);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Failed to load"} />;

  const columns: Col<ShopUser>[] = [
    { header: "Name", cell: ({ row }) => <span className="flex items-center gap-2 font-medium"><Avatar name={row.original.name} url={row.original.photo_url} /> {row.original.name}</span> },
    { header: "Email", accessorKey: "email" },
    { header: "Role", cell: ({ row }) => <Badge tone={row.original.role === "shop_owner" ? "green" : "blue"}>{row.original.role_label}</Badge> },
    { header: "Status", cell: ({ row }) => <Badge tone={row.original.is_active ? "green" : "gray"}>{row.original.is_active ? "Active" : "Disabled"}</Badge> },
    { header: "Action", id: "actions", cell: ({ row }) => row.original.role !== "shop_owner" && <Button variant="outline" onClick={() => { setEditing(row.original); setOpen(true); }}><Pencil className="size-4 text-sky-600" /> Edit</Button> },
  ];

  return (
    <>
      <PageHeader title="Users" actions={<Button onClick={() => { setEditing(null); setOpen(true); }}><Plus className="size-4" /> New user</Button>} />
      <DataTable columns={columns} data={data} />
      <UserForm open={open} user={editing} onClose={() => setOpen(false)} onSaved={reload} />
    </>
  );
}
