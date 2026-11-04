"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { CheckCircle2, Send } from "lucide-react";
import { api, ApiError, applyServerErrors } from "@/lib/api";
import { Button, Field, inputCls } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const schema = z.object({
  name: z.string().min(1, "Your name is required.").max(100),
  email: z.string().min(1, "Email is required.").max(255).email("Enter a valid email address."),
  message: z.string().min(1, "Message is required.").min(10, "Write at least 10 characters.").max(1000, "Keep your message under 1000 characters."),
  leave_blank: z.string(),
});
type Values = z.infer<typeof schema>;

/** The landing page's contact form. The server emails the message to support and checks the address can receive a reply. */
export function ContactForm() {
  const [sent, setSent] = useState(false);
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", email: "", message: "", leave_blank: "" },
  });

  const onSubmit = async (values: Values) => {
    try {
      await api("/contact", { method: "POST", body: values });
      reset();
      setSent(true);
      toast.success("Message sent");
    } catch (e) {
      const message = applyServerErrors(e, setError);
      toast.error(e instanceof ApiError && e.status === 429 ? "Too many messages. Please try again later." : message);
    }
  };

  if (sent) {
    return (
      <div role="status" className="flex flex-col items-center gap-2 rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-emerald-200">
        <CheckCircle2 className="size-10 text-emerald-700" />
        <p className="font-semibold text-slate-800">Thanks, we got your message.</p>
        <p className="text-sm text-slate-600">We will reply to the email address you gave us.</p>
        <button type="button" onClick={() => setSent(false)} className="mt-2 text-sm font-medium text-emerald-700 underline">Send another message</button>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate className="relative space-y-3 rounded-xl bg-white p-5 shadow-sm ring-1 ring-emerald-200">
      {/* off screen rather than display:none, which bots skip; a person never reaches it. Named so browsers and password managers leave it alone: if one fills it, the message is dropped */}
      <div aria-hidden="true" className="absolute -left-[9999px]">
        <label htmlFor="contact-leave-blank">Leave this empty</label>
        <input id="contact-leave-blank" type="text" tabIndex={-1} autoComplete="off" data-1p-ignore data-lpignore="true" data-bwignore {...register("leave_blank")} />
      </div>
      <Field required label="Your name" error={errors.name?.message}><Input className={inputCls} autoComplete="name" maxLength={100} {...register("name")} /></Field>
      <Field required label="Email" error={errors.email?.message}><Input type="email" className={inputCls} autoComplete="email" maxLength={255} {...register("email")} /></Field>
      <Field required label="Message" error={errors.message?.message}><Textarea className="w-full" rows={4} maxLength={1000} {...register("message")} /></Field>
      <Button type="submit" disabled={isSubmitting} className="w-full"><Send className="size-4" /> {isSubmitting ? "Sending..." : "Send message"}</Button>
    </form>
  );
}
