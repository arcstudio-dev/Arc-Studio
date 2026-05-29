// ══════════════════════════════════════════════════════════════
// VOLTIQ Service Worker — Offline-Modus
// Version: wird bei Änderungen hochgezählt → Cache wird erneuert
// ══════════════════════════════════════════════════════════════

var CACHE_NAME = 'arc-v46';

var FILES_TO_CACHE = [
  // ── Kern-Apps ─────────────────────────────────────────────
  '../voltiq.html',
  '../index.html',
  '../impressum.html',
  '../datenschutz.html',
  '../voltech-base.css',
  '../voltech-base.css?v=2',

  // ── Berufs-Apps ───────────────────────────────────────────
  '../VOLTECH-ampere.html',
  '../VOLTECH-torque.html',
  '../FISI-core.html',
  '../FISI-lern.html',

  // ── Shared Scripts ────────────────────────────────────────
  '../VOLTECH-sharecard.js',
  '../VOLTECH-pwa.js',
  '../VOLTECH-notif.js',
  '../VOLTECH-wochenrueckblick.js',
    
  // ── Lern-Suite & Tools ────────────────────────────────────
  '../VOLTECH-lern-hub.html',
  '../VOLTECH-lern-basis.html',
  '../VOLTECH-lern-extra.html',
  '../VOLTECH-lernplan.html',
  '../VOLTECH-lernpfade.html',
  '../VOLTECH-mechatronik-pro.html',
  '../VOLTECH-sps.html',
  '../VOLTECH-berichtsheft.html',
  '../VOLTECH-tools2.html',
  '../VOLTECH-eigene-karten.html',
  '../VOLTECH-achievements.html',
  '../VOLTECH-analytics.html',
  '../VOLTECH-ap2-sim.html',
  '../VOLTECH-ki.html',
  '../VOLTECH-ki-quiz.html',
  '../VOLTECH-whats-new.html',
  '../VOLTECH-focus.html',
  '../VOLTECH-export.html',
  '../VOLTECH-pwa-guide.html',
  '../VOLTECH-tts.html',
  '../VOLTECH-coach.html',
  '../VOLTECH-qr.html',
  '../VOLTECH-pruefung-sim.html',
  '../VOLTECH-lerngruppen.html',
  '../VOLTECH-quests.html',
  '../VOLTECH-season.html',
  '../VOLTECH-duell.html',
  '../VOLTECH-rangliste.html',
  '../VOLTECH-ki-lernplan.html',
  '../VOLTECH-dashboard.html',
  '../VOLTECH-wochenbericht.html',
  '../VOLTECH-ausbilder-fragen.html',
  '../VOLTECH-suche.html',
  '../VOLTECH-cross-sr.html',
  '../VOLTECH-lernkurven.html',
  '../VOLTECH-vergleich.html',
  '../VOLTECH-tandem.html',
  '../VOLTECH-foto-karte.html',
  '../VOLTECH-mentor.html',
  '../spark-kfz.html',

  // ── Weitere Apps ──────────────────────────────────────────
  '../VOLTECH-ihk-archiv.html',
  '../VOLTECH-english.html',
  '../VOLTECH-ki-tutor.html',
  '../VOLTECH-klassen.html',

  // ── Community ─────────────────────────────────────────────
  '../VOLTECH-forum.html',
  '../quiz-live.html',
  '../ausbilder.html',
  '../schule.html',

  // ── PWA ───────────────────────────────────────────────────
  '../pwa/manifest.json',
  '../pwa/icon-192.png',
  '../pwa/icon-512.png',
];

// ── INSTALL ──────────────────────────────────────────────────
self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      console.log('[VOLTIQ SW] Cache wird befüllt...');
      var results = FILES_TO_CACHE.map(function(url) {
        return cache.add(url).catch(function(err) {
          console.warn('[VOLTIQ SW] Konnte nicht cachen:', url, err);
        });
      });
      return Promise.all(results);
    }).then(function() {
      console.log('[VOLTIQ SW] Install abgeschlossen — Offline-Modus aktiv');
      return self.skipWaiting();
    })
  );
});

// ── ACTIVATE: veraltete Caches löschen ───────────────────────
self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(cacheNames) {
      return Promise.all(
        cacheNames
          .filter(function(name) {
            return name !== CACHE_NAME && name !== CACHE_NAME + '-fonts';
          })
          .map(function(name) {
            console.log('[VOLTIQ SW] Alter Cache gelöscht:', name);
            return caches.delete(name);
          })
      );
    }).then(function() {
      return self.clients.claim();
    })
  );
});

// ── FETCH: Stale-While-Revalidate ────────────────────────────
self.addEventListener('fetch', function(event) {
  if (event.request.method !== 'GET') return;

  var url = new URL(event.request.url);

  // Externe Fonts: Network-First mit Cache-Fallback
  if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
    event.respondWith(
      caches.open(CACHE_NAME + '-fonts').then(function(cache) {
        return fetch(event.request).then(function(response) {
          if (response && response.status === 200) {
            cache.put(event.request, response.clone());
          }
          return response;
        }).catch(function() {
          return cache.match(event.request);
        });
      })
    );
    return;
  }

  if (url.origin !== self.location.origin) return;

  // Lokale Dateien: Cache-First + Stale-While-Revalidate
  event.respondWith(
    caches.match(event.request).then(function(cached) {
      if (cached) {
        caches.open(CACHE_NAME).then(function(cache) {
          return fetch(event.request).then(function(networkResponse) {
            if (networkResponse && networkResponse.status === 200) {
              cache.put(event.request, networkResponse.clone());
            }
          }).catch(function() {});
        });
        return cached;
      }
      return fetch(event.request).then(function(networkResponse) {
        if (networkResponse && networkResponse.status === 200) {
          caches.open(CACHE_NAME).then(function(cache) {
            cache.put(event.request, networkResponse.clone());
          });
        }
        return networkResponse;
      }).catch(function() {
        return caches.match('../voltiq.html');
      });
    })
  );
});

// ── MESSAGE ───────────────────────────────────────────────────
self.addEventListener('message', function(event) {
  if (event.data === 'skipWaiting') {
    self.skipWaiting();
  }
  if (event.data === 'clearCache') {
    caches.keys().then(function(names) {
      return Promise.all(names.map(function(n) { return caches.delete(n); }));
    }).then(function() {
      console.log('[VOLTIQ SW] Alle Caches geleert');
    });
  }
  if (event.data && event.data.type === 'SHOW_REMINDER') {
    self.registration.showNotification(event.data.title, {
      body: event.data.body,
      icon: '../pwa/icon-192.png',
      badge: '../pwa/icon-192.png',
      data: { url: event.data.url },
      vibrate: [200, 100, 200]
    });
  }
});

// ── NOTIFICATIONCLICK ─────────────────────────────────────────
self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then(function(clientList) {
      for (var c of clientList) { if (c.focus) return c.focus(); }
      return clients.openWindow(
        (event.notification.data && event.notification.data.url) || '/'
      );
    })
  );
});
