import type { MetadataRoute } from "next";
import { SITE_URL } from "@/lib/site";

// fixed dates on purpose: Google ignores lastmod when it changes on every build without the page changing
export default function sitemap(): MetadataRoute.Sitemap {
  return [
    { url: SITE_URL, lastModified: "2026-11-05", changeFrequency: "weekly", priority: 1 },
    { url: `${SITE_URL}/signup`, lastModified: "2026-11-05", changeFrequency: "monthly", priority: 0.8 },
    { url: `${SITE_URL}/login`, lastModified: "2026-11-05", changeFrequency: "yearly", priority: 0.5 },
    { url: `${SITE_URL}/privacy`, lastModified: "2026-10-30", changeFrequency: "yearly", priority: 0.3 },
    { url: `${SITE_URL}/terms`, lastModified: "2026-10-30", changeFrequency: "yearly", priority: 0.3 },
  ];
}
