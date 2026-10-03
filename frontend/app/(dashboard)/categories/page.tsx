"use client";

import { savedMsg } from "@/lib/format";
import { useEffect, useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, ApiError, applyServerErrors } from "@/lib/api";
import { useCategories, VehicleCategory } from "@/lib/categories";
import { Button, Field, inputCls, Modal, PageHeader } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { Input } from "@/components/ui/input";

const schema = z.object({ name: z.string().min(1, "Category name is required."), default_brand: z.string(), default_model: z.string(), default_color: z.string(), default_cc: z.string().refine((v) => v === "" || (Number(v) >= 1 && Number(v) <= 10000), "Enter a valid CC.") });
type Values = z.infer<typeof schema>;

function CategoryForm({ open, category, onClose, onSaved }: { open: boolean; category: VehicleCategory | null; onClose: () => void; onSaved: () => void }) {
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { name: "", default_brand: "", default_model: "", default_color: "", default_cc: "" } });

  useEffect(() => {
    if (open) reset({ name: category?.name ?? "", default_brand: category?.default_brand ?? "", default_model: category?.default_model ?? "", default_color: category?.default_color ?? "", default_cc: category?.default_cc ? String(category.default_cc) : "" });
  }, [open, category, reset]);

  const onSubmit = async (v: Values) => {
    try {
      await api(category ? `/vehicle-categories/${category.id}` : "/vehicle-categories", { method: category ? "PUT" : "POST", body: { name: v.name, default_brand: v.default_brand || null, default_model: v.default_model || null, default_color: v.default_color || null, default_cc: v.default_cc ? Number(v.default_cc) : null } });
      toast.success(savedMsg("Category", !!category));
      onSaved();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={category ? "Edit category" : "New category"}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
        <Field required label="Category name" error={errors.name?.message}><Input className={inputCls} placeholder="e.g. Tempo Truck" {...register("name")} /></Field>
        <Field label="Default brand (optional)" error={errors.default_brand?.message}><Input className={inputCls} placeholder="e.g. Bajaj, filled in automatically when this type is chosen" {...register("default_brand")} /></Field>
        <Field label="Default model (optional)" error={errors.default_model?.message}><Input className={inputCls} placeholder="e.g. RE Compact, filled in automatically when this type is chosen" {...register("default_model")} /></Field>
        <Field label="Default colour (optional)" error={errors.default_color?.message}><Input className={inputCls} placeholder="e.g. Green, selected automatically when this type is chosen" {...register("default_color")} /></Field>
        <Field label="Default CC (optional)" error={errors.default_cc?.message}><Input type="number" className={inputCls} placeholder="e.g. 200, filled in automatically when this type is chosen" {...register("default_cc")} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save"}</Button>
        </div>
      </form>
    </Modal>
  );
}

export default function CategoriesPage() {
  const { categories, reload } = useCategories();
  const [editing, setEditing] = useState<VehicleCategory | null>(null);
  const [open, setOpen] = useState(false);

  const remove = async (c: VehicleCategory) => {
    if (!confirm(`Delete category "${c.name}"?`)) return;
    try {
      await api(`/vehicle-categories/${c.id}`, { method: "DELETE" });
      toast.success("Category deleted successfully");
      reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    }
  };

  const columns: Col<VehicleCategory>[] = [
    { header: "Category", cell: ({ row }) => <span className="font-medium">{row.original.name}</span> },
    { header: "Default brand", cell: ({ row }) => row.original.default_brand ?? "-" },
    { header: "Default model", cell: ({ row }) => row.original.default_model ?? "-" },
    { header: "Default colour", cell: ({ row }) => row.original.default_color ?? "-" },
    { header: "Default CC", cell: ({ row }) => row.original.default_cc ?? "-" },
    { header: "Key", cell: ({ row }) => <code className="text-xs text-slate-500">{row.original.key}</code> },
    {
      header: "",
      id: "actions",
      cell: ({ row }) => (
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => { setEditing(row.original); setOpen(true); }}><Pencil className="size-4 text-sky-600" /> Edit</Button>
          <Button variant="danger" onClick={() => remove(row.original)}><Trash2 className="size-4" /> Delete</Button>
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader title="Vehicle categories" actions={<Button onClick={() => { setEditing(null); setOpen(true); }}><Plus className="size-4" /> New category</Button>} />
      <p className="mb-4 text-sm text-slate-600">Categories you add here are available to every shop. A category only shows as a tab on a shop&apos;s Vehicles page once it has at least one vehicle.</p>
      <DataTable columns={columns} data={categories} empty="No categories yet." />
      <CategoryForm open={open} category={editing} onClose={() => setOpen(false)} onSaved={reload} />
    </>
  );
}
