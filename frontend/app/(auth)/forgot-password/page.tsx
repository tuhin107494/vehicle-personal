"use client";

import { useState } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { AuthShell } from "@/components/AuthShell";
import { Button, Field } from "@/components/app-ui";
import { Input } from "@/components/ui/input";

const schema = z.object({ email: z.string().min(1, "Email is required.").email("Enter a valid email.") });
type Values = z.infer<typeof schema>;

export default function ForgotPasswordPage() {
  const [sent, setSent] = useState("");
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema) });

  const onSubmit = async (v: Values) => {
    try {
      await api("/forgot-password", { method: "POST", body: v });
      setSent(v.email);
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <AuthShell title="Forgot password" subtitle="We will email you a link to set a new one">
      {sent ? (
        <p className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">If <b>{sent}</b> has an account, a reset link is on its way. The link works for 60 minutes.</p>
      ) : (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <Field required label="Email" error={errors.email?.message}><Input type="email" className="w-full" {...register("email")} /></Field>
          <Button type="submit" disabled={isSubmitting} className="w-full">{isSubmitting ? "Sending..." : "Send reset link"}</Button>
        </form>
      )}
      <p className="text-center text-sm"><Link className="font-medium text-emerald-700 underline" href="/login">Back to sign in</Link></p>
    </AuthShell>
  );
}
