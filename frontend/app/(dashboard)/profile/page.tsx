"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { useAuth, type User } from "@/lib/auth";
import { Button, Card, Field, inputCls, PageHeader } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { PhotoField } from "@/components/PhotoField";
import { savePhoto, type PhotoChange } from "@/lib/photo";

const schema = z.object({
  name: z.string().min(1, "Name is required."),
  email: z.string().min(1, "Email is required.").email("Enter a valid email address."),
  current_password: z.string(),
  password: z.string(),
  password_confirmation: z.string(),
}).superRefine((v, ctx) => {
  if (!v.password) return;
  if (v.password.length < 8) ctx.addIssue({ code: "custom", path: ["password"], message: "Password must be at least 8 characters." });
  if (!v.current_password) ctx.addIssue({ code: "custom", path: ["current_password"], message: "Enter your current password to set a new one." });
  if (v.password !== v.password_confirmation) ctx.addIssue({ code: "custom", path: ["password_confirmation"], message: "The two passwords do not match." });
});
type Values = z.infer<typeof schema>;

export default function ProfilePage() {
  const { user, setUser } = useAuth();
  const [photo, setPhoto] = useState<PhotoChange>(null);
  const { register, handleSubmit, reset, setError, watch, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    values: { name: user?.name ?? "", email: user?.email ?? "", current_password: "", password: "", password_confirmation: "" },
  });

  const onSubmit = async (v: Values) => {
    try {
      const res = await api<User>("/profile", {
        method: "PUT",
        body: { name: v.name, email: v.email, ...(v.password && { current_password: v.current_password, password: v.password, password_confirmation: v.password_confirmation }) },
      });
      let saved = res.data;
      try {
        const url = await savePhoto("/profile", photo);
        if (url !== undefined) saved = { ...saved, photo_url: url };
      } catch {
        toast.error("Your details were saved, but the photo could not be uploaded.");
      }
      setPhoto(null);
      setUser(saved);
      reset({ name: saved.name, email: saved.email, current_password: "", password: "", password_confirmation: "" });
      toast.success("Profile updated successfully");
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <div>
      <PageHeader title="Profile settings" />
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="max-w-xl space-y-4">
        <Card title="Your details">
          <div className="space-y-3">
            <PhotoField name={watch("name")} url={user?.photo_url} change={photo} onChange={setPhoto} />
            <Field required label="Name" error={errors.name?.message}><Input className={inputCls} autoComplete="name" {...register("name")} /></Field>
            <Field required label="Email" error={errors.email?.message}><Input type="email" className={inputCls} autoComplete="email" {...register("email")} /></Field>
          </div>
        </Card>
        <Card title="Change password">
          <p className="mb-3 text-xs text-slate-500">Leave blank to keep your current password.</p>
          <div className="space-y-3">
            <Field label="Current password" error={errors.current_password?.message}><Input type="password" className={inputCls} autoComplete="current-password" {...register("current_password")} /></Field>
            <Field label="New password" error={errors.password?.message}><Input type="password" className={inputCls} autoComplete="new-password" {...register("password")} /></Field>
            <Field label="Confirm new password" error={errors.password_confirmation?.message}><Input type="password" className={inputCls} autoComplete="new-password" {...register("password_confirmation")} /></Field>
          </div>
        </Card>
        <div className="flex justify-end"><Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save"}</Button></div>
      </form>
    </div>
  );
}
