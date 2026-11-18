"use client";

import { useAuth } from "@/lib/auth";
import { daysLeft, subscriptionState } from "@/lib/subscription";

const SHOW_WITHIN_DAYS = 3;

/** Warns a shop whose trial or subscription ends within 3 days, or has ended. The profile page always shows it. */
export function SubscriptionBanner() {
  const { user } = useAuth();
  const sub = user?.shop?.subscription;
  if (!sub) return null;

  const { ended, trial, days } = subscriptionState(sub);
  if (!ended && days > SHOW_WITHIN_DAYS) return null;

  return ended
    ? <p role="alert" className="bg-red-50 px-4 py-2 text-center text-sm font-medium text-red-800 print:hidden">Your subscription has ended. Your shop is read-only: you can view and export, but not make changes. Contact support to continue.</p>
    : <p className="bg-amber-50 px-4 py-2 text-center text-sm font-medium text-amber-800 print:hidden">{trial ? "Free trial" : "Subscription"}: {daysLeft(days)}.</p>;
}
