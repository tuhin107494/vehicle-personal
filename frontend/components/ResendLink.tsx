"use client";

import { useState } from "react";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { Button, Field } from "@/components/app-ui";
import { Input } from "@/components/ui/input";

/** Sends the sign-up confirmation link again. With `email` it is one button; without, it asks for the email first. */
export function ResendLink({ email: fixed }: { email?: string }) {
  const [email, setEmail] = useState(fixed ?? "");
  const [sending, setSending] = useState(false);

  const send = async () => {
    setSending(true);
    try {
      const res = await api("/signup/resend", { method: "POST", body: { email } });
      toast.success(res.message);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "Something went wrong");
    } finally {
      setSending(false);
    }
  };

  const button = <Button variant="outline" className="w-full" disabled={sending || !email} onClick={send}>{sending ? "Sending..." : "Send the link again"}</Button>;

  if (fixed) return button;

  return (
    <div className="space-y-3">
      <Field label="Email"><Input type="email" className="w-full" value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
      {button}
    </div>
  );
}
