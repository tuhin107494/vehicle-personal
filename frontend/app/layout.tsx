import type { Metadata, Viewport } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import { Toaster } from "sonner";
import { ServiceWorkerRegister } from "@/components/ServiceWorkerRegister";
import { AuthProvider } from "@/lib/auth";
import { LangProvider } from "@/lib/i18n";
import { SITE_DESCRIPTION, SITE_NAME, SITE_URL } from "@/lib/site";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: SITE_NAME,
  description: SITE_DESCRIPTION,
  applicationName: SITE_NAME,
  openGraph: {
    type: "website",
    siteName: SITE_NAME,
    title: "Gari Becho · Vehicle shop management",
    description: SITE_DESCRIPTION,
    locale: "en_US",
    alternateLocale: ["bn_BD"],
    images: [{ url: "/icons/icon-512.png", width: 512, height: 512, alt: SITE_NAME }],
  },
  twitter: { card: "summary", title: "Gari Becho · Vehicle shop management", description: SITE_DESCRIPTION, images: ["/icons/icon-512.png"] },
  robots: { index: true, follow: true, googleBot: { index: true, follow: true, "max-image-preview": "large", "max-snippet": -1, "max-video-preview": -1 } },
  appleWebApp: { capable: true, title: "Gari Becho", statusBarStyle: "default" },
  // naming icons here switches off Next's automatic app/icon.svg link, so the favicon is listed too
  icons: { icon: { url: "/icon.svg", type: "image/svg+xml" }, apple: "/apple-touch-icon.png" },
  // appleWebApp only writes the newer mobile-web-app-capable tag; older iPhones look for this one
  other: { "apple-mobile-web-app-capable": "yes" },
};

export const viewport: Viewport = {
  themeColor: "#059669",
  viewportFit: "cover",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="min-h-full flex flex-col"><LangProvider><AuthProvider>{children}</AuthProvider></LangProvider>
        <Toaster richColors position="top-right" offset={{ top: "calc(24px + env(safe-area-inset-top))" }} mobileOffset={{ top: "calc(16px + env(safe-area-inset-top))" }} />
        <ServiceWorkerRegister />
      </body>
    </html>
  );
}
