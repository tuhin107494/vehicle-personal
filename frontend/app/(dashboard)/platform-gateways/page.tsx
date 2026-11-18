"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Plus } from "lucide-react";
import { useFetch } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import type { GatewayConfigT } from "@/lib/types";
import { Button, Card, ErrorText, Loading, PageHeader } from "@/components/app-ui";
import { AddGatewayForm, type AddableGateway } from "@/components/forms/AddGatewayForm";

/** The gateway a menu click opens: bKash when it is set up, otherwise the first one there is. */
const PREFERRED = "bkash";

/**
 * Payment gateways goes straight to the gateway's settings page: there is one gateway to look after, so a list in
 * between is one click too many. Only while no gateway exists does this page show, to add one.
 */
export default function PlatformGatewaysPage() {
  const { t } = useT();
  const router = useRouter();
  const list = useFetch<GatewayConfigT[]>("/admin/gateways");
  const [adding, setAdding] = useState(false);
  const rows = list.data ?? [];
  const addable = (list.meta as { addable?: AddableGateway[] } | null)?.addable ?? [];
  const target = rows.find((g) => g.code === PREFERRED) ?? rows[0];

  // replace, not push: Back on the settings page then leaves the gateways area instead of landing here and bouncing again
  useEffect(() => {
    if (target) router.replace(`/platform-gateways/${target.code}`);
  }, [target, router]);

  if (list.error) return <ErrorText message={list.error} />;
  if (!list.data || target) return <Loading />;

  return (
    <>
      <PageHeader title="Payment gateways" />
      <Card>
        <div className="flex flex-col items-start gap-3 py-2">
          <p className="text-sm text-slate-600">{t("No payment gateway is set up yet. Add one with its keys to let shops pay their invoices.")}</p>
          <Button disabled={addable.length === 0} onClick={() => setAdding(true)}><Plus className="size-4" /> Add gateway</Button>
        </div>
      </Card>
      <AddGatewayForm open={adding} addable={addable} onClose={() => setAdding(false)} onSaved={list.reload} />
    </>
  );
}
