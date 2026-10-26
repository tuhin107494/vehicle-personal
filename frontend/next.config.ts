import type { NextConfig } from "next";

const isProd = process.env.NODE_ENV === "production";
const apiOrigin = (() => {
  try {
    return new URL(process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api").origin;
  } catch {
    return "http://localhost:8000";
  }
})();

// Google's sign-in script, which the sign-in page loads when the super admin has turned Google sign-in on
const google = "https://accounts.google.com/gsi/";

// the page may talk to itself, the API and Google sign-in only; scripts and styles stay inline because Next.js writes some inline
const csp = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline' ${google}client${isProd ? "" : " 'unsafe-eval'"}`,
  `style-src 'self' 'unsafe-inline' ${google}style`,
  `img-src 'self' data: blob: ${apiOrigin}`,
  "font-src 'self' data:",
  `frame-src ${google}`,
  `connect-src 'self' ${apiOrigin} ${google}${isProd ? "" : " ws://localhost:3000 ws://127.0.0.1:3000"}`,
  "frame-ancestors 'none'",
  "base-uri 'self'",
  "form-action 'self'",
].join("; ");

const nextConfig: NextConfig = {
  poweredByHeader: false,
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Frame-Options", value: "DENY" },
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
          { key: "Content-Security-Policy", value: csp },
          // HTTPS only once it is live; sending it on http://localhost would do nothing
          ...(isProd ? [{ key: "Strict-Transport-Security", value: "max-age=63072000; includeSubDomains" }] : []),
        ],
      },
    ];
  },
};

export default nextConfig;
