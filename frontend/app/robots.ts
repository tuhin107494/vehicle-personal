import type { MetadataRoute } from "next";
import { SITE_URL } from "@/lib/site";

// signed-in screens (they render nothing for a crawler) and one-time links from emails; keep in step with app/(dashboard) and app/(auth)
const PRIVATE_PATHS = [
  "/api/",
  "/account-payable-receivable",
  "/bookings",
  "/categories",
  "/collections",
  "/customers",
  "/daily-report",
  "/dashboard",
  "/due-collection",
  "/investor-payment",
  "/investor-sales",
  "/investor-transactions",
  "/investors",
  "/permissions",
  "/platform-settings",
  "/profile",
  "/purchases",
  "/reports",
  "/roles",
  "/sales",
  "/settings",
  "/shops",
  "/supplier-payment",
  "/suppliers",
  "/users",
  "/vehicles",
  "/forgot-password",
  "/reset-password",
  "/verify-email",
  "/offline.html",
];

/** Every crawler, search and AI alike, may read the public pages. */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: { userAgent: "*", allow: "/", disallow: PRIVATE_PATHS },
    sitemap: `${SITE_URL}/sitemap.xml`,
    host: SITE_URL,
  };
}
