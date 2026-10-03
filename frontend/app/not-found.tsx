import Link from "next/link";

export default function NotFound() {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-3 px-4 text-center">
      <h1 className="text-xl font-bold text-slate-800">Page not found</h1>
      <p className="text-sm text-slate-600">The page you are looking for does not exist.</p>
      <Link className="font-medium text-emerald-700 underline" href="/">Go to the dashboard</Link>
    </main>
  );
}
