"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { CircleCheck } from "lucide-react";
import { api, applyServerErrors } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { AuthShell } from "@/components/AuthShell";
import { authError, GoogleButton, OrDivider, useAuthConfig, useGoogleCode } from "@/components/GoogleSignIn";
import { Button, Field, Loading } from "@/components/app-ui";
import { Input } from "@/components/ui/input";

const schema = z.object({
  shop_name: z.string().trim().min(1, "Shop name is required."),
  phone: z.string().trim().min(1, "Phone is required."),
  name: z.string().trim().min(1, "Your name is required."),
  email: z.string().min(1, "Email is required.").email("Enter a valid email."),
  password: z.string().min(8, "Password must be at least 8 characters."),
  password_confirmation: z.string(),
}).refine((v) => v.password === v.password_confirmation, { path: ["password_confirmation"], message: "The two passwords do not match." });
type Values = z.infer<typeof schema>;

/** Sign-up: a new shop with its owner. The shop waits until the super admin approves it. */
export default function SignupPage() {
  const router = useRouter();
  const { user, loading } = useAuth();
  const config = useAuthConfig();
  const [done, setDone] = useState("");
  const { register, handleSubmit, setError, clearErrors, getValues, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema) });

  useEffect(() => {
    if (!loading && user) router.replace(user.role === "super_admin" ? "/shops" : "/");
  }, [loading, user, router]);

  const onSubmit = async (v: Values) => {
    try {
      const res = await api("/signup", { method: "POST", body: v });
      setDone(res.message);
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  // Google gives the name and email; the shop's name and phone still come from the form
  const google = useGoogleCode(config?.google.client_id, async (code) => {
    try {
      const res = await api("/signup/google", { method: "POST", body: { code, shop_name: getValues("shop_name"), phone: getValues("phone") } });
      setDone(res.message);
    } catch (e) {
      applyServerErrors(e, setError);
      toast.error(authError(e));
    }
  });

  // checked here, not with the async form check: the browser only allows Google's popup straight from the click
  const signUpWithGoogle = () => {
    clearErrors();
    const missing = (["shop_name", "phone"] as const).filter((f) => !getValues(f)?.trim());
    missing.forEach((f) => setError(f, { message: f === "shop_name" ? "Shop name is required." : "Phone is required." }));
    if (!missing.length) google.start();
  };

  if (done) {
    return (
      <AuthShell title="Account created" subtitle="One more step">
        <div className="flex gap-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800"><CircleCheck className="size-5 shrink-0" /> {done}</div>
        <p className="text-center text-sm"><Link className="font-medium text-emerald-700 underline" href="/login">Back to sign in</Link></p>
      </AuthShell>
    );
  }

  if (!config) return <AuthShell title="Create an account" subtitle="Open your shop on Vehicle Hub"><Loading /></AuthShell>;

  if (!config.signup_enabled) {
    return (
      <AuthShell title="Create an account" subtitle="Open your shop on Vehicle Hub">
        <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Sign-up is closed right now. Contact support to open an account.</p>
        <p className="text-center text-sm"><Link className="font-medium text-emerald-700 underline" href="/login">Back to sign in</Link></p>
      </AuthShell>
    );
  }

  return (
    <AuthShell title="Create an account" subtitle="Open your shop on Vehicle Hub">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3" noValidate>
        <Field required label="Shop name" error={errors.shop_name?.message}><Input className="w-full" autoComplete="organization" {...register("shop_name")} /></Field>
        <Field required label="Phone" error={errors.phone?.message}><Input type="tel" className="w-full" autoComplete="tel" {...register("phone")} /></Field>

        {google.ready && (
          <>
            <GoogleButton label="Sign up with Google" busy={google.busy} onClick={signUpWithGoogle} />
            <OrDivider />
          </>
        )}

        <Field required label="Your name" error={errors.name?.message}><Input className="w-full" autoComplete="name" {...register("name")} /></Field>
        <Field required label="Email" error={errors.email?.message}><Input type="email" className="w-full" autoComplete="email" {...register("email")} /></Field>
        <Field required label="Password" error={errors.password?.message}><Input type="password" className="w-full" autoComplete="new-password" {...register("password")} /></Field>
        <Field required label="Confirm password" error={errors.password_confirmation?.message}><Input type="password" className="w-full" autoComplete="new-password" {...register("password_confirmation")} /></Field>
        <Button type="submit" disabled={isSubmitting} className="w-full">{isSubmitting ? "Creating..." : "Create account"}</Button>
        <p className="text-center text-xs text-slate-500">Your shop can sign in once it is approved.</p>
      </form>
      <p className="text-center text-sm text-slate-600">Already have an account? <Link className="font-medium text-emerald-700 underline" href="/login">Sign in</Link></p>
    </AuthShell>
  );
}
