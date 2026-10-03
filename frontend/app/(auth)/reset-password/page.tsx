"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { AuthShell } from "@/components/AuthShell";
import { Button, Field } from "@/components/app-ui";
import { Input } from "@/components/ui/input";

const schema = z.object({
  password: z.string().min(8, "Password must be at least 8 characters."),
  password_confirmation: z.string(),
}).refine((v) => v.password === v.password_confirmation, { path: ["password_confirmation"], message: "The two passwords do not match." });
type Values = z.infer<typeof schema>;

function ResetForm() {
  const router = useRouter();
  const params = useSearchParams();
  const token = params.get("token") ?? "";
  const email = params.get("email") ?? "";
  const [done, setDone] = useState(false);
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema) });

  const onSubmit = async (v: Values) => {
    try {
      const res = await api("/reset-password", { method: "POST", body: { ...v, token, email } });
      toast.success(res.message);
      setDone(true);
      setTimeout(() => router.replace("/login"), 1500);
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  if (!token || !email) {
    return (
      <AuthShell title="Reset password" subtitle="This link is incomplete">
        <p className="text-center text-sm text-slate-600">Open the link from your email again, or <Link className="font-medium text-emerald-700 underline" href="/forgot-password">ask for a new one</Link>.</p>
      </AuthShell>
    );
  }

  return (
    <AuthShell title="Reset password" subtitle={email}>
      {done ? (
        <p className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">Password changed. Taking you to sign in...</p>
      ) : (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <Field required label="New password" error={errors.password?.message}><Input type="password" className="w-full" {...register("password")} /></Field>
          <Field required label="Confirm password" error={errors.password_confirmation?.message}><Input type="password" className="w-full" {...register("password_confirmation")} /></Field>
          <Button type="submit" disabled={isSubmitting} className="w-full">{isSubmitting ? "Saving..." : "Change password"}</Button>
        </form>
      )}
      <p className="text-center text-sm"><Link className="font-medium text-emerald-700 underline" href="/login">Back to sign in</Link></p>
    </AuthShell>
  );
}

export default function ResetPasswordPage() {
  return <Suspense><ResetForm /></Suspense>;
}
