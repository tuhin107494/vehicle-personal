import type { Metadata } from "next";
import { ContactEmail, LegalPage } from "@/components/LegalPage";

export const metadata: Metadata = { title: "Privacy Policy · Gari Becho", alternates: { canonical: "/privacy" } };

export default function PrivacyPage() {
  return (
    <LegalPage title="Privacy Policy" updated="30 October 2026">
      <p>
        Gari Becho is a tool vehicle shops use to manage their stock, purchases, sales, collections and investors. This policy
        explains what information we collect, why, and what you can do about it. It is operated by Beacon Soft Ltd.
      </p>

      <section>
        <h2>What we collect</h2>
        <ul>
          <li><b>Account details:</b> your name, email address, phone number, shop name and a password (stored only as a secure hash).</li>
          <li><b>Google sign-in:</b> if you sign in or sign up with Google, we receive your Google account name and email address. We do not get your Google password or access to your other Google data.</li>
          <li><b>Profile photo:</b> only if you choose to upload one.</li>
          <li><b>Shop records:</b> the vehicles, customers, suppliers, investors, payments and other records your shop enters into the app.</li>
          <li><b>Technical data:</b> a sign-in token kept in your browser so you stay signed in, and standard server logs (such as IP address and time of request) used for security and troubleshooting.</li>
        </ul>
      </section>

      <section>
        <h2>Why we collect it</h2>
        <ul>
          <li>To create your account and sign you in.</li>
          <li>To run your shop&apos;s vehicle, sales and collection records and show them to the people your shop gives access to.</li>
          <li>To send account emails, such as confirming your email address or resetting your password.</li>
          <li>To keep the service secure and working.</li>
        </ul>
      </section>

      <section>
        <h2>Sharing</h2>
        <p>
          We do not sell your information, and we do not share it with anyone for advertising. Records belong to your shop and are
          visible only to users of that shop. We use hosting and email providers to run the service; they process data only on our
          behalf. We may disclose information if the law requires it.
        </p>
      </section>

      <section>
        <h2>Google user data</h2>
        <p>
          Information received from Google is used only to sign you in and to fill in your account&apos;s name and email. It is not
          used for advertising, not sold, and not shared with third parties. Gari Becho&apos;s use of information received from
          Google APIs adheres to the Google API Services User Data Policy, including the Limited Use requirements.
        </p>
      </section>

      <section>
        <h2>How long we keep it</h2>
        <p>
          We keep your account and shop records for as long as the account is active. When an account is deleted, its personal
          data is removed from our systems, except where we must keep something to meet a legal obligation.
        </p>
      </section>

      <section>
        <h2>Your choices and deleting your data</h2>
        <p>
          You can view and update your name, phone and photo on your profile page. To get a copy of your data, or to have your
          account and its data deleted, email us at <ContactEmail /> from the address on your account. We will act on the request
          within 30 days.
        </p>
      </section>

      <section>
        <h2>Security</h2>
        <p>
          Data is sent over encrypted connections (HTTPS) and passwords are stored as hashes. No system is perfectly secure, but we
          take reasonable steps to protect your information.
        </p>
      </section>

      <section>
        <h2>Changes</h2>
        <p>If we change this policy, we will update the date at the top of this page.</p>
      </section>

      <section>
        <h2>Contact</h2>
        <p>Questions about this policy or your data: <ContactEmail />.</p>
      </section>
    </LegalPage>
  );
}
