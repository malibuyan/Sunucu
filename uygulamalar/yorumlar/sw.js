// Uygulama kabuğunu önbelleğe alır; veriler (api.php) her zaman sunucudan gelir.
const ONBELLEK = 'yl-v1';
const KABUK = ['./', 'index.html', 'manifest.json', 'ikon-192.png', 'ikon-512.png'];
self.addEventListener('install', ev => { ev.waitUntil(caches.open(ONBELLEK).then(c => c.addAll(KABUK))); self.skipWaiting(); });
self.addEventListener('activate', ev => {
  ev.waitUntil(caches.keys().then(k => Promise.all(k.filter(x => x !== ONBELLEK).map(x => caches.delete(x)))));
  self.clients.claim();
});
self.addEventListener('fetch', ev => {
  const url = new URL(ev.request.url);
  if (ev.request.method !== 'GET' || url.origin !== location.origin || url.pathname.endsWith('api.php')) return;
  ev.respondWith(
    fetch(ev.request).then(r => { const k = r.clone(); caches.open(ONBELLEK).then(c => c.put(ev.request, k)); return r; })
      .catch(() => caches.match(ev.request))
  );
});
