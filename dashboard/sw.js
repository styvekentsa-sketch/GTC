/**
 * Global Trading Corporation — Service worker de l'application « GTC Dashboard » (portée : /dashboard/)
 *
 * Rend le Dashboard installable (ordinateur, Android, iPhone) et affiche une page « hors connexion »
 * si le réseau est coupé. Volontairement minimal : AUCUNE page du Dashboard n'est mise en cache
 * (données confidentielles, toujours lues en direct sur le serveur) ; seuls la page hors connexion
 * et le logo le sont. Changer VERSION à chaque modification de ce fichier.
 *
 * v3 : une coupure de quelques secondes (réveil de l'ordinateur, Wi-Fi qui se reconnecte, serveur un instant
 * lent à répondre) n'affiche plus la page « hors connexion » : la page est redemandée deux fois avant.
 * Le logo de la page hors connexion vient du cache (il s'affichait cassé), et cette page se recharge
 * d'elle-même dès que le serveur répond (dashboard/offline.html).
 */
const VERSION = 'gtc-dashboard-v3';
const OFFLINE_URL = 'offline.html';
const LOGO = '../assets/img/logo-gtc-436.png';
const PRECACHE = [OFFLINE_URL, LOGO, '../assets/img/app-192.png'];
const RETRIES = [1000, 2500];   // nouvelles tentatives (ms) avant d'afficher la page hors connexion

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

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Page demandée au réseau, avec deux nouvelles tentatives si le serveur est momentanément injoignable. */
async function navigate(request) {
  for (let attempt = 0; ; attempt++) {
    try {
      return await fetch(request);
    } catch (error) {
      if (attempt >= RETRIES.length) {
        return (await caches.match(OFFLINE_URL)) || Response.error();
      }
      await wait(RETRIES[attempt]);
    }
  }
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  // Pages : toujours le réseau (jamais de copie des données) ; page « hors connexion » en dernier recours
  if (request.mode === 'navigate') {
    event.respondWith(navigate(request));
    return;
  }
  // Logo et icône de la page hors connexion : copie locale si le réseau ne répond pas
  const path = new URL(request.url).pathname;
  if (request.method === 'GET' && (path.endsWith('/assets/img/logo-gtc-436.png') || path.endsWith('/assets/img/app-192.png'))) {
    event.respondWith(fetch(request).catch(() => caches.match(path.endsWith('app-192.png') ? '../assets/img/app-192.png' : LOGO)));
  }
  // Toutes les autres requêtes (scripts, styles, API) ne passent pas par le service worker
});
