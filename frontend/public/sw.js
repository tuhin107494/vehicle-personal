// A deliberately small service worker: it makes the app installable and gives it an offline page.
// This app shows live financial data, so only files that cannot go stale are ever stored.
// Change CACHE when this file's caching changes; activate deletes the older caches.
const CACHE = "gari-becho-v1";
const OFFLINE_URL = "/offline.html";

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.add(new Request(OFFLINE_URL, { cache: "reload" }))));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((names) => Promise.all(names.filter((name) => name !== CACHE).map((name) => caches.delete(name))))
      .then(() => self.clients.claim()),
  );
});

// Content-hashed files never change under the same name, so the stored copy is always right.
async function cacheFirst(request) {
  const stored = await caches.match(request);
  if (stored) return stored;

  const response = await fetch(request);
  if (response.ok && response.type === "basic") {
    const copy = response.clone();
    caches.open(CACHE).then((cache) => cache.put(request, copy));
  }
  return response;
}

self.addEventListener("fetch", (event) => {
  const { request } = event;

  // returning without respondWith leaves the request to the browser, as if this worker did not exist
  if (request.method !== "GET" || request.headers.has("Authorization")) return;

  const url = new URL(request.url);
  // the API lives on its own host; everything that is not this site is left alone
  if (url.origin !== self.location.origin || url.pathname.startsWith("/api/")) return;

  if (request.mode === "navigate") {
    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
    return;
  }

  if (url.pathname.startsWith("/_next/static/") || url.pathname.startsWith("/icons/")) {
    event.respondWith(cacheFirst(request));
  }
});
