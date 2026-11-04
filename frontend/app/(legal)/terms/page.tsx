import type { Metadata } from "next";
import Link from "next/link";
import { ContactEmail, LegalPage } from "@/components/LegalPage";

export const metadata: Metadata = { title: "Terms of Service · Gari Becho" };

export default function TermsPage() {
  return (
    <LegalPage title="Terms of Service" updated="30 October 2026">
      <p>
        These terms apply when you use Gari Becho, a service operated by Beacon Soft Ltd. By creating an account or signing in,
        you agree to them.
      </p>

      <section>
        <h2>Your account</h2>
        <ul>
          <li>Give accurate details when you sign up, and keep your password private.</li>
          <li>You are responsible for what happens under your account and for the users you add to your shop.</li>
          <li>Tell us at <ContactEmail /> if you think someone else has used your account.</li>
        </ul>
      </section>

      <section>
        <h2>Your data</h2>
        <p>
          The records your shop enters stay yours. You give us permission to store and process them only to provide the service.
          How we handle personal information is explained in our{" "}
          <Link className="font-medium text-emerald-700 underline" href="/privacy">Privacy Policy</Link>.
        </p>
      </section>

      <section>
        <h2>Acceptable use</h2>
        <ul>
          <li>Use Gari Becho only for lawful business purposes.</li>
          <li>Do not try to access other shops&apos; data, break the service&apos;s security, or overload it.</li>
          <li>Do not enter information you have no right to store.</li>
        </ul>
      </section>

      <section>
        <h2>The service</h2>
        <p>
          We work to keep Gari Becho available and accurate, but it is provided &quot;as is&quot; without guarantees. You are
          responsible for checking figures before relying on them for financial or legal decisions. To the extent the law allows,
          we are not liable for indirect losses arising from use of the service.
        </p>
      </section>

      <section>
        <h2>Ending your account</h2>
        <p>
          You can ask us to close your account at any time by emailing <ContactEmail />. We may suspend accounts that break these
          terms.
        </p>
      </section>

      <section>
        <h2>Changes</h2>
        <p>If we change these terms, we will update the date at the top of this page. Continuing to use the service means you accept the new terms.</p>
      </section>

      <section>
        <h2>Contact</h2>
        <p>Questions about these terms: <ContactEmail />.</p>
      </section>
    </LegalPage>
  );
}
