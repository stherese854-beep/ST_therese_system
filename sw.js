/* St. Therese Dental Clinic: minimal service worker.
   It exists only so Chrome / Edge offer "Install app". It caches NOTHING and every request goes
   straight to the network, so pages, uploads and patient data are never served from a cache. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
    // remove any cache a past version might have made, then take control of open tabs
    e.waitUntil(caches.keys().then(function (keys) { return Promise.all(keys.map(function (k) { return caches.delete(k); })); })
        .then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (event) {
    event.respondWith(fetch(event.request));   // network only
});
