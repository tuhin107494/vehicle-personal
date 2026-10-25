"use client";

import { useEffect, useState } from "react";
import { Mail, Save, Send } from "lucide-react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { Button, Card, ErrorText, Field, inputCls, Loading, PageHeader } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

/** What the server sends: every value except the secrets, which only say whether one is saved. */
type Saved = {
  signup: { enabled: boolean };
  google: { enabled: boolean; client_id: string | null; client_secret_set: boolean };
  smtp: {
    enabled: boolean; host: string | null; port: number | null; encryption: string | null; username: string | null;
    password_set: boolean; from_address: string | null; from_name: string | null;
  };
};

type Values = {
  signup: { enabled: boolean };
  google: { enabled: boolean; client_id: string; client_secret: string };
  smtp: { enabled: boolean; host: string; port: string; encryption: string; username: string; password: string; from_address: string; from_name: string };
};

// secrets always start blank: blank keeps the saved one
const valuesFrom = (s: Saved): Values => ({
  signup: { enabled: s.signup.enabled },
  google: { enabled: s.google.enabled, client_id: s.google.client_id ?? "", client_secret: "" },
  smtp: {
    enabled: s.smtp.enabled, host: s.smtp.host ?? "", port: s.smtp.port ? String(s.smtp.port) : "", encryption: s.smtp.encryption ?? "tls",
    username: s.smtp.username ?? "", password: "", from_address: s.smtp.from_address ?? "", from_name: s.smtp.from_name ?? "",
  },
});

const Toggle = ({ label, ...props }: { label: string } & React.ComponentProps<"input">) => (
  <label className="flex items-center gap-2 text-sm font-medium text-slate-700"><input type="checkbox" className="size-4 accent-emerald-600" {...props} /> {label}</label>
);

export default function PlatformSettingsPage() {
  const { user } = useAuth();
  const [saved, setSaved] = useState<Saved | null>(null);
  const [error, setError] = useState("");
  const [testTo, setTestTo] = useState(user?.email ?? "");
  const [testing, setTesting] = useState(false);
  const { register, handleSubmit, reset, setValue, setError: setFieldError, formState: { errors, isSubmitting, isDirty } } = useForm<Values>();

  useEffect(() => {
    api<Saved>("/platform-settings")
      .then((res) => { setSaved(res.data); reset(valuesFrom(res.data)); })
      .catch((e) => setError(e.message));
  }, [reset]);

  const onSubmit = async (v: Values) => {
    try {
      const res = await api<Saved>("/platform-settings", {
        method: "PUT",
        body: { signup: v.signup, google: v.google, smtp: { ...v.smtp, port: v.smtp.port ? Number(v.smtp.port) : null } },
      });
      setSaved(res.data);
      reset(valuesFrom(res.data));
      toast.success("Settings saved");
    } catch (e) {
      toast.error(applyServerErrors(e, setFieldError));
    }
  };

  const sendTest = async () => {
    setTesting(true);
    try {
      const res = await api("/platform-settings/test-mail", { method: "POST", body: { to: testTo } });
      toast.success(res.message);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "Something went wrong");
    } finally {
      setTesting(false);
    }
  };

  const useGmail = () => {
    setValue("smtp.host", "smtp.gmail.com", { shouldDirty: true });
    setValue("smtp.port", "587", { shouldDirty: true });
    setValue("smtp.encryption", "tls", { shouldDirty: true });
  };

  if (error) return <ErrorText message={error} />;
  if (!saved) return <Loading />;

  const origin = window.location.origin; // only reached in the browser, once the settings have loaded
  const keepHint =(set: boolean) => (set ? "Saved. Leave blank to keep it" : "");

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <PageHeader title="System settings" actions={<Button type="submit" disabled={isSubmitting || !isDirty}><Save className="size-4" /> {isSubmitting ? "Saving..." : "Save"}</Button>} />

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Sign-up" className="lg:col-span-2">
          <div className="space-y-2">
            <Toggle label="Let new shops create an account from the sign-in page" {...register("signup.enabled")} />
            <p className="text-sm text-slate-500">With email and password, we email a confirmation link; the shop shows as Pending in Shops until the owner opens it, which opens the shop and signs them in. Sign-ups never confirmed are deleted after 7 days. With Google (the email is already proven) the shop opens at once. You can switch any shop off in Shops, and super admins get an email for each new shop.</p>
            {saved.signup.enabled && !saved.smtp.enabled && (
              <p className="rounded-lg bg-amber-50 p-2 text-sm text-amber-800">Email (SMTP) below is off, so confirmation links go through the server&apos;s .env mail settings and may never arrive. Set up SMTP so email sign-ups can confirm.</p>
            )}
          </div>
        </Card>

        <Card title="Google sign-in">
          <div className="space-y-3">
            <p className="text-sm text-slate-500">
              Lets users sign in with the Google account that uses the same email as their account here. Create an OAuth client ID
              (type: Web application) in the Google Cloud Console under APIs &amp; Services → Credentials, and add
              <code className="mx-1 rounded bg-slate-100 px-1 text-slate-700">{origin}</code>
              as an authorised JavaScript origin.
            </p>
            <Toggle label="Allow sign-in with Google" {...register("google.enabled")} />
            <Field label="Client ID" error={errors.google?.client_id?.message}>
              <Input className={inputCls} placeholder="xxxxxxxx.apps.googleusercontent.com" autoComplete="off" {...register("google.client_id")} />
            </Field>
            <Field label="Client secret" error={errors.google?.client_secret?.message}>
              <Input type="password" className={inputCls} placeholder={keepHint(saved.google.client_secret_set)} autoComplete="new-password" {...register("google.client_secret")} />
            </Field>
          </div>
        </Card>

        <Card title="Email (SMTP)" actions={<Button variant="outline" onClick={useGmail}><Mail className="size-4" /> Use Gmail</Button>}>
          <div className="space-y-3">
            <p className="text-sm text-slate-500">
              Password reset and other emails go out through this server. For Gmail, turn on 2-step verification and use an
              App Password (Google Account → Security → App passwords), not your normal password.
            </p>
            <Toggle label="Send email through this SMTP server" {...register("smtp.enabled")} />
            <div className="grid gap-3 sm:grid-cols-[1fr_7rem_8rem]">
              <Field label="SMTP host" error={errors.smtp?.host?.message}><Input className={inputCls} placeholder="smtp.gmail.com" {...register("smtp.host")} /></Field>
              <Field label="Port" error={errors.smtp?.port?.message}><Input type="number" className={inputCls} placeholder="587" {...register("smtp.port")} /></Field>
              <Field label="Encryption" error={errors.smtp?.encryption?.message}>
                <NativeSelect className="w-full" {...register("smtp.encryption")}>
                  <option value="tls">TLS (587)</option>
                  <option value="ssl">SSL (465)</option>
                </NativeSelect>
              </Field>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Username" error={errors.smtp?.username?.message}><Input className={inputCls} placeholder="you@gmail.com" autoComplete="off" {...register("smtp.username")} /></Field>
              <Field label="Password" error={errors.smtp?.password?.message}>
                <Input type="password" className={inputCls} placeholder={keepHint(saved.smtp.password_set)} autoComplete="new-password" {...register("smtp.password")} />
              </Field>
              <Field label="From address" error={errors.smtp?.from_address?.message}><Input type="email" className={inputCls} placeholder="you@gmail.com" {...register("smtp.from_address")} /></Field>
              <Field label="From name" error={errors.smtp?.from_name?.message}><Input className={inputCls} placeholder="Vehicle Hub" {...register("smtp.from_name")} /></Field>
            </div>
            <div className="border-t border-slate-200 pt-3">
              <Field label="Send a test email to">
                <div className="flex gap-2">
                  <Input type="email" className={inputCls} value={testTo} onChange={(e) => setTestTo(e.target.value)} />
                  <Button variant="outline" disabled={testing || !testTo || isDirty || !saved.smtp.enabled} onClick={sendTest}><Send className="size-4" /> {testing ? "Sending..." : "Send"}</Button>
                </div>
              </Field>
              {(isDirty || !saved.smtp.enabled) && <p className="mt-1 text-xs text-slate-500">Turn on SMTP and save before sending a test.</p>}
            </div>
          </div>
        </Card>
      </div>
    </form>
  );
}
