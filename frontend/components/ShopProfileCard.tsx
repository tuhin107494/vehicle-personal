"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { useFetch } from "@/lib/hooks";
import { Button, Card, Field, inputCls } from "@/components/app-ui";
import { Input } from "@/components/ui/input";

type Shop = { id: number; name: string };

const schema = z.object({
  name: z.string().min(1, "Shop name is required."),
});
type Values = z.infer<typeof schema>;

/** The shop's own name: the owner can change them here. */
export function ShopProfileCard() {
  const { user, setUser } = useAuth();
  const shop = useFetch<Shop>("/shop");
  const { register, handleSubmit, setError, formState: { errors, isSubmitting, isDirty } } = useForm<Values>({
    resolver: zodResolver(schema),
    values: { name: shop.data?.name ?? "" },
  });

  const onSubmit = async (v: Values) => {
    try {
      const res = await api<Shop>("/shop", { method: "PUT", body: { name: v.name } });
      if (user) setUser({ ...user, shop: { id: res.data.id, name: res.data.name } }); // the menu shows the new name at once
      shop.reload();
      toast.success("Shop updated successfully");
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Card title="Shop" className="mb-4">
      {/* not a <form>: the Settings page is already one, and forms cannot nest */}
      <div className="space-y-3" onKeyDown={(e) => { if (e.key === "Enter" && (e.target as HTMLElement).tagName === "INPUT") { e.preventDefault(); handleSubmit(onSubmit)(); } }}>
        <Field required label="Shop name" error={errors.name?.message}><Input className={inputCls} {...register("name")} /></Field>
        <div className="flex justify-end"><Button type="button" onClick={handleSubmit(onSubmit)} disabled={isSubmitting || !isDirty}>{isSubmitting ? "Saving..." : "Save shop"}</Button></div>
      </div>
    </Card>
  );
}
