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
};
export type Vehicle = {
  id: number; chassis_no: string; engine_no: string; registration_no: string | null; vehicle_type: string; brand: string | null;
  model: string | null; year: number | null; color: string | null; cc: number | null; fuel_type: string | null;
  registration_date: string | null; tax_token_expiry: string | null; fitness_expiry: string | null; insurance_expiry: string | null;
  papers_received: boolean; mileage: number | null; condition_notes: string | null;
  status: "in_stock" | "sold" | "booked"; current_sale_id?: number | null; current_purchase?: Purchase; current_sale?: Sale;
  days_in_stock?: number | null; sale_due?: number | null; registration_status?: string | null;
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
  id: number; sale_id: number; installment_id: number | null; amount: number; method: string;
  paid_at: string; reference: string | null; note: string | null; receiver?: { id: number; name: string };
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
  payment_type: "cash" | "due" | "installment"; guarantor_phone: string | null; total_paid: number; due_amount: number; grand_total: number;
  payment_status: "paid" | "partial" | "unpaid"; status: "active" | "cancelled"; cancel_reason: string | null;
  registration_status: string; vehicle?: Vehicle; customer?: Customer; investor?: Investor; plan?: Plan;
  payments?: Payment[]; promises?: Promise_[]; registration_logs?: RegLog[];
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
  owner?: { id: number; name: string; email: string; is_active: boolean } | null; users_count?: number;
};
export type PermissionT = { id: number; key: string; name: string; group: string; description: string | null };
export type ShopUser = { id: number; name: string; email: string; role: string; role_label: string; is_active: boolean; photo_url?: string | null };
export type CallRow = {
  type: "installment" | "promise"; id: number; label: string; sale_id: number; due_date: string; days_left: number;
  amount: number; sale_due: number; customer_total_due: number;
  overdue_by: { months: number; days: number; total_days: number } | null;
  customer: { id: number; name: string; phone: string; alt_phone: string | null; address: string | null };
  vehicle: { id: number; chassis_no: string; brand: string | null; model: string | null };
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
  saved_by: string | null; updated_at: string;
};

export type AccountSummary = {
  person: { id: number; name: string; phone: string; alt_phone: string | null; bank_name?: string | null; bank_branch?: string | null; bank_account_name?: string | null; bank_account_no?: string | null; bank_info?: string | null };
  total_due: number; advance_available: number; current_due?: number; days_late?: number;
  items: { kind: "sale" | "purchase"; id: number; date: string; vehicle: string; due: number; vehicle_name?: string; engine_no?: string | null; memo_no?: string | null; payment_type?: string; current_due?: number; days_late?: number; next_date?: string | null }[];
};
