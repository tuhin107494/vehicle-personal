"use client";

import { LoginPoster } from "@/components/LoginPoster";
import { useEffect } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { useAuth } from "@/lib/auth";
import { applyServerErrors } from "@/lib/api";
import { Car } from "lucide-react";
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
      toast.error(applyServerErrors(e, setError));
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
            <div className="text-right"><Link className="text-sm font-medium text-emerald-700 hover:underline" href="/forgot-password">Forgot password?</Link></div>
            <Button type="submit" disabled={isSubmitting} className="w-full">
              {isSubmitting ? "Signing in..." : "Sign in"}
            </Button>
          </form>
        </CardContent>
      </Card>
      </div>
    </main>
  );
}
