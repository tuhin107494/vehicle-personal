const DAY = 24 * 60 * 60 * 1000;

/** A shop's current subscription, as the API sends it on the signed-in user and on each shop. */
export type SubscriptionSummary = { plan: string; status: "trialing" | "active" | "expired" | "past_due" | "cancelled"; ends_at: string };

/** Where a subscription stands: whether it ended, and the whole days left (rounded up). */
export function subscriptionState(s: SubscriptionSummary): { ended: boolean; trial: boolean; days: number } {
  const left = new Date(s.ends_at).getTime() - Date.now();
  const ended = s.status === "expired" || s.status === "cancelled" || left <= 0;
  return { ended, trial: s.status === "trialing", days: Math.max(Math.ceil(left / DAY), 0) };
}

export function daysLeft(days: number): string {
  return days === 1 ? "1 day left" : `${days} days left`;
}
