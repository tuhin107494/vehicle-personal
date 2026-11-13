/** GET /landing: what the public landing page shows. A detail left blank in System settings stays off the page. */
export type LandingInfo = {
  signup_enabled: boolean; contact_form: boolean; support_email: string | null; support_phone: string | null; whatsapp: string | null; address: string | null; support_hours: string | null;
};
export type Customer = {
  id: number; name: string; father_name: string | null; phone: string; alt_phone: string | null; nid: string | null;
  address: string | null; notes: string | null; receivable_due?: number; photo_url?: string | null;
};
export type Supplier = {
  id: number; name: string; phone: string; alt_phone: string | null; nid: string | null; address: string | null; notes: string | null; photo_url?: string | null;
  bank_name?: string | null; bank_branch?: string | null; bank_account_name?: string | null; bank_account_no?: string | null; bank_info?: string | null; payable_due?: number;
};
/** Whoever sold a purchase to us: the supplier, or the customer for a second-hand buy-back. */
export type Seller = { id: number; name: string; phone: string; alt_phone: string | null; nid?: string | null; photo_url?: string | null };
export type Investor = {
  id: number; name: string; phone: string | null; alt_phone: string | null; nid: string | null; address: string | null;
  bank_name?: string | null; bank_branch?: string | null; bank_account_name?: string | null; bank_account_no?: string | null; bank_info: string | null; opening_balance: number;
  current_due?: number; installments_due?: number; photo_url?: string | null;
  formula_invested?: number | null; formula_profit?: number | null; formula_months?: number | null; formula_rate_month?: number | null; formula_rate_year?: number | null; notes: string | null; is_active: boolean;
};
export type Purchase = {
  id: number; vehicle_id: number; supplier_id: number | null; customer_id: number | null; purchase_date: string; price: number; paid_amount: number;
  cost_extra: number; due_adjustment: number; adjusted_sale_id: number | null; source: "supplier" | "customer"; memo_no: string | null; payment_status: string; payment_method: string | null; payment_reference: string | null; notes: string | null; vehicle?: Vehicle; supplier?: Supplier; customer?: Customer; seller?: Seller;
  has_payments?: boolean; edits?: { id: number; by: string | null; at: string; changes: { field: string; from: string | null; to: string | null }[] }[];
};
export type Vehicle = {
  id: number; chassis_no: string; engine_no: string; registration_no: string | null; vehicle_type: string; brand: string | null;
  model: string | null; year: number | null; color: string | null; cc: number | null; fuel_type: string | null;
  registration_date: string | null; tax_token_expiry: string | null; fitness_expiry: string | null; insurance_expiry: string | null;
  papers_received: boolean; mileage: number | null; condition_notes: string | null;
  status: "in_stock" | "sold" | "booked"; current_sale_id?: number | null; current_purchase?: Purchase; current_sale?: Sale;
  days_in_stock?: number | null; sale_due?: number | null; booking_id?: number | null; draft_id?: number | null; registration_status?: string | null;
};
export type Installment = {
  id: number; plan_id: number; seq_no: number; due_date: string; amount: number; paid_amount: number;
  status: string; paid_at: string | null; plan?: Plan;
};
export type Plan = {
  id: number; sale_id: number; principal: number; interest_rate: number; interest_amount: number;
  total_payable: number; number_of_installments: number; frequency: string; start_date: string;
  investor_share_percent: number; installments?: Installment[]; sale?: Sale;
};
export type Payment = {
  id: number; sale_id: number; installment_id: number | null; batch_no?: string | null; amount: number; method: string;
  paid_at: string; reference: string | null; memo_no?: string | null; note: string | null; receiver?: { id: number; name: string };
};
export type Promise_ = {
  id: number; sale_id: number; promised_date: string; promised_amount: number;
  status: "open" | "kept" | "broken" | "cancelled"; note: string | null; sale?: Sale;
};
export type RegLog = {
  id: number; from_status: string; to_status: string; changed_at: string; note: string | null;
  user?: { id: number; name: string };
};
export type Sale = {
  id: number; vehicle_id: number; customer_id: number; sale_date: string; sale_price: number; down_payment: number;
  payment_type: "cash" | "due" | "installment"; guarantor_phone: string | null; guarantor_name?: string | null; memo_no?: string | null; total_paid: number; due_amount: number; grand_total: number;
  payment_status: "paid" | "partial" | "unpaid"; status: "active" | "cancelled"; cancel_reason: string | null;
  registration_status: string; vehicle?: Vehicle; customer?: Customer; investor?: Investor; plan?: Plan;
  payments?: Payment[]; promises?: Promise_[]; registration_logs?: RegLog[]; charges?: { id: number; purpose: string; amount: number; charged_on: string; note: string | null; paid: number | null }[]; edits?: { id: number; by: string | null; at: string; changes: { field: string; from: string | null; to: string | null }[] }[];
  booking?: { id: number; advance_amount: number; advance_method: string; booked_on: string; expires_on: string | null; agreed_price: number | null; note: string | null; booked_by: string | null } | null; // the booking this sale completed
  advance?: { amount: number; date: string; method: string; booking_id: number | null } | null; // received before the sale: a booking's advance or an advance payment
};
export type Settlement = {
  id: number; investor_id: number; type: string; amount: number; date: string; note: string | null;
  investor?: Investor;
};
export type Summary = {
  opening_balance: number; invested: number; returned: number; profit_earned: number; withdrawn: number;
  outstanding: number; sales_count: number; collected: number; sales_due: number;
};
export type Shop = {
  id: number; name: string; phone: string | null; address: string | null; is_active: boolean;
  approved_at: string | null; // empty: signed itself up and waits for the super admin to switch it on
  owner?: { id: number; name: string; email: string; is_active: boolean } | null; users_count?: number;
};
export type PermissionT = { id: number; key: string; name: string; group: string; description: string | null };
export type ShopUser = { id: number; name: string; email: string; role: string; role_label: string; is_active: boolean; photo_url?: string | null };
export type CallRow = {
  type: "installment" | "promise"; id: number; label: string; sale_id: number; due_date: string; days_left: number;
  amount: number; sale_due: number; customer_total_due: number; guarantor_phone: string | null;
  overdue_by: { months: number; days: number; total_days: number } | null;
  customer: { id: number; name: string; phone: string; alt_phone: string | null; address: string | null };
  vehicle: { id: number; chassis_no: string; engine_no: string | null; brand: string | null; model: string | null };
  /** set when the row was marked "call done": the day it comes back to the list */
  called_until: string | null;
  /** what the customer said on that call: the day they will meet the shop, and a note */
  called_meet_date: string | null;
  called_note: string | null;
};
/** What a half-finished form saves: its field values plus the page state needed to reopen it. */
export type DraftData = {
  values?: Record<string, unknown>;
  source?: "supplier" | "customer"; // purchase: bought from a supplier or back from a customer
  picked?: string; // purchase: the chosen seller
  typeFilter?: string; // sale: the vehicle type filter
};
export type DraftT = {
  id: number; type: "purchase" | "sale"; label: string | null; data: DraftData;
  expires_on?: string | null; vehicle_id?: number | null; vehicle?: { id: number; chassis_no: string; brand: string | null; model: string | null; year: number | null; vehicle_type: string } | null; customer?: { id: number; name: string; phone: string; alt_phone: string | null } | null; // the vehicle it holds and the customer chosen so far (in the booking list)
  saved_by: string | null; updated_at: string;
};

export type AccountSummary = {
  person: { id: number; name: string; phone: string; alt_phone: string | null; bank_name?: string | null; bank_branch?: string | null; bank_account_name?: string | null; bank_account_no?: string | null; bank_info?: string | null };
  total_due: number; advance_available: number; current_due?: number; days_late?: number;
  items: { kind: "sale" | "purchase"; id: number; date: string; vehicle: string; due: number; vehicle_name?: string; engine_no?: string | null; memo_no?: string | null; payment_type?: string; current_due?: number; days_late?: number; next_date?: string | null }[];
};

export type Booking = {
  id: number; vehicle_id: number | null; customer_id: number; agreed_price: string | null; advance_amount: string; advance_method: "cash" | "bank" | "mobile";
  booked_on: string; expires_on: string | null; status: "active" | "completed" | "cancelled" | "expired"; sale_id: number | null;
  refund_amount: string; kept_amount: string; closed_on: string | null; note: string | null; close_note: string | null;
  balance: number | null; days_left: number | null;
  booked_by?: string | null;
  vehicle?: { id: number; chassis_no: string; engine_no: string; brand: string | null; model: string | null; year: number | null; vehicle_type: string; status: string };
  customer?: { id: number; name: string; phone: string; alt_phone: string | null };
};

/** One setting a payment gateway needs; the configure form is built from these (GatewayConfigController@show → details.schema). */
export type CredentialFieldT = { name: string; label: string; type: "text" | "password" | "textarea" | "url" | "select" | "boolean"; required: boolean; is_secret: boolean; help: string | null; options: Record<string, string> };
export type GatewayMode = "sandbox" | "live";
/** A payment gateway as the super admin sees it. Secrets are never sent: a saved one shows as a masked tail. */
export type GatewayConfigT = {
  id: number; code: string; display_name: string; logo_url: string | null; description: string | null; is_active: boolean; sort_order: number;
  active_mode: GatewayMode; min_amount_paisa: number | null; max_amount_paisa: number | null; fee_percent: number; fee_fixed_paisa: number; webhook_ips: string | null;
  configured: { sandbox: boolean; live: boolean } | null; updated_at: string | null;
  details?: { schema: CredentialFieldT[]; sandbox: Record<string, string | boolean | null>; live: Record<string, string | boolean | null>; configured: { sandbox: boolean; live: boolean }; urls: Record<string, string> };
};
export type PaymentAttemptStatus = "initiated" | "pending" | "success" | "failed" | "cancelled" | "expired";
export type PaymentAttemptT = {
  id: number; invoice_id: number; shop_id: number; gateway: string; amount_paisa: number; currency: string; status: PaymentAttemptStatus; failure_reason: string | null;
  reference: string | null; proof_url: string | null; submitted_at: string | null; expires_at: string | null; verified_at: string | null; created_at: string | null;
  invoice?: { invoice_no: string; total_paisa: number; status: string } | null; shop?: { id: number; name: string } | null;
  initiation?: { kind: "redirect" | "instructions"; redirect_url: string | null; instructions: string | null; details: Record<string, string> } | null;
};
/** The shop's checkout: which gateways it may pay an invoice through (GET /billing/invoices/{id}/gateways). */
export type GatewayOptionT = { code: string; display_name: string; logo_url: string | null; description: string | null; mode: GatewayMode };
export type FeatureType = "boolean" | "limit";
export type FeatureT = { key: string; type: FeatureType; description: string };
/** How long one paid period of a plan lasts: 1, 3, 6 or 12 months (Plan::INTERVALS). */
export type BillingInterval = "monthly" | "quarterly" | "half_yearly" | "yearly";
/** A platform plan. `features` is key => "1"/"0" for a switch, a number or null (unlimited) for a limit. */
export type PlanT = {
  /** price_paisa is what the shop pays; original_price_paisa is the regular price before discount (display only), null without one */
  id: number; name: string; slug: string; price_paisa: number; original_price_paisa: number | null; discount_paisa: number; discount_percent: number; currency: string; billing_interval: BillingInterval; trial_days: number; is_active: boolean; sort_order: number;
  description: string | null; features?: Record<string, string | null>; subscriptions_count?: number;
};
export type SubscriptionStatus = "trialing" | "active" | "past_due" | "expired" | "cancelled";
export type SubscriptionT = {
  id: number; shop_id: number; status: SubscriptionStatus; starts_at: string; ends_at: string; trial_ends_at: string | null; grace_ends_at: string | null; cancelled_at: string | null;
  auto_renew: boolean; days_left: number; plan?: PlanT; pending_plan?: PlanT | null; shop?: { id: number; name: string; phone: string | null; is_active: boolean } | null; updated_at: string | null;
};
export type InvoiceT = {
  id: number; shop_id: number; subscription_id: number; invoice_no: string; status: "unpaid" | "paid" | "void"; payable: boolean; amount_paisa: number; discount_paisa: number; total_paisa: number; currency: string;
  period_starts_at: string; period_ends_at: string; due_date: string; paid_at: string | null; created_at: string | null;
  plan?: { id: number; name: string; billing_interval: string } | null; coupon?: { id: number; code: string; type: string; value: number } | null; shop?: { id: number; name: string; phone: string | null; address: string | null } | null;
};
/** What a limit or switch allows this shop right now (GET /billing/subscription → usage). */
export type UsageT = { type: "limit"; limit: number | null; used: number; remaining: number | null } | { type: "boolean"; allowed: boolean };
export type BillingOverviewT = { subscription: SubscriptionT | null; usage: Record<string, UsageT>; open_invoice: InvoiceT | null; grace_days: number };
export type CouponT = { id: number; code: string; type: "percent" | "fixed"; value: number; max_uses: number | null; used_count: number; expires_at: string | null; is_active: boolean; redeemable: boolean; created_at: string | null };
export type GatewayRefundT = {
  id: number; gateway_payment_id: number; shop_id: number; amount_paisa: number; reason: string; status: "pending" | "success" | "failed"; gateway_refund_id: string | null; failure_reason: string | null;
  requested_by?: { id: number; name: string } | null; payment?: { id: number; gateway_code: string; gateway_trx_id: string; amount_paisa: number } | null; shop?: { id: number; name: string } | null; created_at: string | null;
};
export type GatewayPaymentT = {
  id: number; attempt_id: number; invoice_id: number; shop_id: number; gateway: string; gateway_trx_id: string; amount_paisa: number; fee_paisa: number; refunded_paisa: number; refundable_paisa: number;
  status: "success" | "partially_refunded" | "refunded"; paid_at: string; invoice?: { id: number; invoice_no: string; status: string; total_paisa: number } | null; shop?: { id: number; name: string } | null; refunds?: GatewayRefundT[];
};
export type MismatchT = {
  id: number; shop_id: number | null; gateway: string; reference_type: string; reference_id: number; kind: "amount" | "status" | "ledger"; expected_paisa: number | null; actual_paisa: number | null; message: string;
  details: unknown; resolved_at: string | null; resolved_by?: { id: number; name: string } | null; note: string | null; shop?: { id: number; name: string } | null; created_at: string | null;
};
export type RevenueReportT = {
  from: string; to: string;
  totals: { payments: number; amount_paisa: number; fee_paisa: number; refunded_paisa: number; net_paisa: number };
  by_gateway: { gateway: string; payments: number; amount_paisa: number; fee_paisa: number }[];
  by_month: { month: string; payments: number; amount_paisa: number; fee_paisa: number; refunded_paisa: number }[];
  subscriptions: { by_status: Record<string, number>; by_plan: { plan: string; shops: number; paying: number }[]; mrr_paisa: number };
  outstanding: { invoices: number; amount_paisa: number };
};
export type GatewayLogT = { id: number; shop_id: number | null; attempt_id: number | null; gateway: string; direction: "request" | "response" | "webhook" | "error"; action: string; http_status: number | null; ip: string | null; payload: unknown; created_at: string };
