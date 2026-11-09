"use client";

import { savedMsg } from "@/lib/format";
import { useEffect, useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, ApiError, applyServerErrors } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useCategories, VehicleCategory } from "@/lib/categories";
import { Button, Field, inputCls, Modal, PageHeader } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { Input } from "@/components/ui/input";

const schema = z.object({ name: z.string().trim().min(1, "Category name is required.").max(100, "Category name is too long.") });
type Values = z.infer<typeof schema>;

function CategoryForm({ open, category, onClose, onSaved }: { open: boolean; category: VehicleCategory | null; onClose: () => void; onSaved: () => void }) {
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { name: "" } });

  useEffect(() => {
    if (open) reset({ name: category?.name ?? "" });
  }, [open, category, reset]);

  const onSubmit = async (v: Values) => {
    try {
      await api(category ? `/vehicle-categories/${category.id}` : "/vehicle-categories", { method: category ? "PUT" : "POST", body: { name: v.name } });
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
        <Field required label="Category name" error={errors.name?.message}><Input className={inputCls} placeholder="e.g. CNG, Car" {...register("name")} /></Field>
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
  const { user } = useAuth();
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
    { header: "Source", cell: ({ row }) => (row.original.shop_id == null ? "Platform" : "Your shop") },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => row.original.shop_id !== (user?.shop?.id ?? null) ? "-" : (
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
      <p className="mb-4 text-sm text-slate-600">Types you can pick when buying a vehicle. Brand, model, colour, CC and year for each type are set in Settings.</p>
      <DataTable columns={columns} data={categories} empty="No categories yet." />
      <CategoryForm open={open} category={editing} onClose={() => setOpen(false)} onSaved={reload} />
    </>
  );
}
