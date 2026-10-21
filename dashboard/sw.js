/**
 * Global Trading Corporation — Service worker de l'application « GTC Dashboard » (portée : /dashboard/)
 *
 * Rend le Dashboard installable (ordinateur, Android, iPhone) et affiche une page « hors connexion »
 * si le réseau est coupé. Volontairement minimal : AUCUNE page du Dashboard n'est mise en cache
 * (données confidentielles, toujours lues en direct sur le serveur) ; seuls la page hors connexion
 * et le logo le sont. Changer VERSION à chaque modification de ce fichier.
 */
const VERSION = 'gtc-dashboard-v1';
const OFFLINE_URL = 'offline.html';
const PRECACHE = [OFFLINE_URL, '../assets/img/logo-gtc.svg', '../assets/img/app-192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

// Pages : toujours le réseau ; page « hors connexion » seulement si le serveur est injoignable.
// Les autres requêtes (scripts, styles, API) ne passent pas par le service worker.
self.addEventListener('fetch', (event) => {
  if (event.request.mode !== 'navigate') return;
  event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE_URL)));
});
