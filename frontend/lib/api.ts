const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api";
const TOKEN_KEY = "vm_token";

export type ApiEnvelope<T> = {
  success: boolean;
  message: string;
  data: T;
  meta?: PageMeta & { unread_count?: number; total_amount?: number; type_counts?: Record<string, number> };
  errors?: Record<string, string[]>;
};

export type PageMeta = { current_page: number; last_page: number; per_page: number; total: number };

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public errors?: Record<string, string[]>,
  ) {
    super(message);
  }
}

export const tokenStore = {
  get: () => (typeof window === "undefined" ? null : localStorage.getItem(TOKEN_KEY)),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
};

export async function api<T = unknown>(
  path: string,
  options: { method?: string; body?: unknown } = {},
): Promise<ApiEnvelope<T>> {
  const token = tokenStore.get();
  const res = await fetch(`${API_URL}${path}`, {
    method: options.method ?? "GET",
    headers: {
      Accept: "application/json",
      ...(options.body !== undefined && !(options.body instanceof FormData) && { "Content-Type": "application/json" }),
      ...(token && { Authorization: `Bearer ${token}` }),
    },
    body: options.body === undefined ? undefined : options.body instanceof FormData ? options.body : JSON.stringify(options.body),
  });

  const json = await res.json().catch(() => null);

  if (!res.ok || !json?.success) {
    if (res.status === 401 && typeof window !== "undefined" && !path.startsWith("/login")) {
      tokenStore.clear();
      window.location.href = "/login";
    }
    // 403: the server refused this user; say so plainly
    const message = res.status === 403 ? "You don't have permission to do this." : (json?.message ?? "Something went wrong");
    throw new ApiError(message, res.status, json?.errors);
  }

  return json;
}

/** Map Laravel 422 errors onto react-hook-form fields. */
export function applyServerErrors(
  error: unknown,
  setError: (field: any, error: { message: string }) => void,
): string {
  if (error instanceof ApiError) {
    if (error.errors) {
      for (const [field, messages] of Object.entries(error.errors)) {
        setError(field, { message: messages[0] });
      }
    }
    return error.message;
  }
  return "Something went wrong";
}
