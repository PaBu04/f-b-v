/*
 * Service Worker für f-b-v.
 *
 * Er läuft unabhängig von geöffneten Seiten und nimmt Push-Nachrichten
 * entgegen. Zwischengespeichert wird bewusst nichts – die Galerie soll immer
 * den aktuellen Stand zeigen.
 */

self.addEventListener('install', function () {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
  var inhalt = {};
  try {
    inhalt = event.data ? event.data.json() : {};
  } catch (e) {
    inhalt = { body: event.data ? event.data.text() : '' };
  }

  var optionen = {
    body: inhalt.body || '',
    icon: 'icon-192.png',
    badge: 'icon-192.png',
    tag: inhalt.tag || 'f-b-v',
    renotify: true,
    data: { url: inhalt.url || 'index.php' }
  };

  event.waitUntil(self.registration.showNotification(inhalt.title || 'f-b-v', optionen));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  var ziel = (event.notification.data && event.notification.data.url) || 'index.php';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (fenster) {
      for (var i = 0; i < fenster.length; i++) {
        // Ist die Seite schon offen, dorthin wechseln statt neu öffnen
        if ('focus' in fenster[i]) {
          if ('navigate' in fenster[i]) {
            fenster[i].navigate(ziel);
          }
          return fenster[i].focus();
        }
      }
      return self.clients.openWindow(ziel);
    })
  );
});
