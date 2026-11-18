const CACHE = 'pesteh-bazar-v89-lot-cartex';
const ASSETS = ['./', './index.html', './style.css', './app.js', './pay.js', './barcode.js', './shell.js', './modules_s21.js', './pricing.js', './sync_rt.js', './modules_intake.js', './modules_crack.js', './modules_trace.js', './modules_cartex.js', './fmt.js', './code_kind.js', './manifest.json', './favicon.ico', './icons/icon-192.png', './icons/icon-512.png', './icons/icon-maskable-512.png', './icons/apple-touch-icon.png', './icons/logo.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => Promise.all(ASSETS.map((a) => c.add(a).catch(() => {})))));
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

// فقط فایل‌های ثابت اپ کش می‌شوند؛ درخواست‌های API همیشه از شبکه خوانده می‌شوند
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.pathname.includes('/api/')) return;
  e.respondWith(
    caches.match(e.request).then((cached) => cached || fetch(e.request))
  );
});
