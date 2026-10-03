"use client";

import { Suspense } from "react";
import { useSearchParams } from "next/navigation";
import { PageHeader } from "@/components/app-ui";
import { AccountForm } from "@/components/forms/AccountForm";

function Form() {
  return <AccountForm side="supplier" partyId={useSearchParams().get("party_id")} />;
}

export default function Page() {
  return (
    <>
      <PageHeader title="Supplier payment" />
      <Suspense>
        <Form />
      </Suspense>
    </>
  );
}
