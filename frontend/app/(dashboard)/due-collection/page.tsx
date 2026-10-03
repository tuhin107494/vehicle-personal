"use client";

import { Suspense } from "react";
import { useSearchParams } from "next/navigation";
import { PageHeader } from "@/components/app-ui";
import { AccountForm } from "@/components/forms/AccountForm";

function Form() {
  const q = useSearchParams();
  return <AccountForm side="customer" vehicleId={q.get("vehicle_id")} partyId={q.get("party_id")} />;
}

export default function Page() {
  return (
    <>
      <PageHeader title="Due collection" />
      <Suspense>
        <Form />
      </Suspense>
    </>
  );
}
