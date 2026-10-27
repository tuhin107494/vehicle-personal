"use client";

import { LoginPoster } from "@/components/LoginPoster";
import { authError, GoogleSignIn, useAuthConfig } from "@/components/GoogleSignIn";
import { useEffect } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { useAuth } from "@/lib/auth";
import { applyServerErrors } from "@/lib/api";
import { Car, UserPlus } from "lucide-react";
import { Button, Card, Field } from "@/components/app-ui";
import { CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";

const schema = z.object({
  email: z.string().min(1, "Email is required.").email("Enter a valid email."),
  password: z.string().min(1, "Password is required."),
});
type FormValues = z.infer<typeof schema>;

export default function LoginPage() {
  const router = useRouter();
  const { user, loading, login } = useAuth();
  const config = useAuthConfig();
  // the page draws in full at once; a part is only hidden when the settings (saved from the last visit, then fresh) say it is off
  const signupOn = config ? config.signup_enabled : true;
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  useEffect(() => {
    if (!loading && user) router.replace(user.role === "super_admin" ? "/shops" : "/");
  }, [loading, user, router]);

  const onSubmit = async (values: FormValues) => {
    try {
      await login(values.email, values.password);
      toast.success("Logged in");
    } catch (e) {
      applyServerErrors(e, setError);
      toast.error(authError(e)); // "waiting for approval" says more than "Validation failed"
    }
  };

  return (
    <main className="relative flex min-h-screen items-center justify-center px-4 py-6">
      <LoginPoster />
      <div className="relative z-10 w-full max-w-sm">
      <Card className="w-full bg-white/95 shadow-lg ring-1 ring-emerald-300">
        <CardContent className="pt-2">
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
            <div className="text-center">
              <span className="mx-auto mb-2 flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-600 to-green-500 text-white"><Car className="size-6" /></span>
              <h1 className="text-xl font-bold text-slate-800">Vehicle Hub</h1>
              <p className="text-sm text-slate-500">Sign in to manage stock, sales and collections</p>
            </div>
            <Field required label="Email" error={errors.email?.message}>
              <Input id="email" type="email" className="w-full" {...register("email")} />
            </Field>
            <Field required label="Password" error={errors.password?.message}>
              <Input id="password" type="password" className="w-full" {...register("password")} />
            </Field>
            <div className="flex flex-wrap justify-between gap-2">
              {signupOn ? <Link className="text-sm text-slate-500 hover:text-emerald-700 hover:underline" href="/verify-email">Didn&apos;t get the confirmation email?</Link> : <span />}
              <Link className="text-sm font-medium text-emerald-700 hover:underline" href="/forgot-password">Forgot password?</Link>
            </div>
            <Button type="submit" disabled={isSubmitting} className="w-full">
              {isSubmitting ? "Signing in..." : "Sign in"}
            </Button>
            <GoogleSignIn clientId={config?.google.client_id} />
            {signupOn && (
              <div className="border-t border-slate-200 pt-4 text-center">
                <p className="mb-2 text-sm text-slate-500">New to Vehicle Hub?</p>
                <Link href="/signup" className="flex h-10 w-full items-center justify-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">
                  <UserPlus className="size-4" /> Create an account
                </Link>
              </div>
            )}
          </form>
        </CardContent>
      </Card>
      </div>
    </main>
  );
}
