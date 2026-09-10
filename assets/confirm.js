/* f-b-v – Rückfrage vor Formularen, die etwas löschen */

/*
 * Früher stand die Rückfrage als onsubmit-Attribut im HTML. Das verlangt
 * script-src 'unsafe-inline' und war bei Texten mit Apostroph obendrein
 * kaputt. Jetzt trägt das Formular data-confirm="…" und diese Datei hängt
 * sich einmal an document. Bewusst getrennt von app.js, weil admin.php den
 * Fußbereich ohne app.js ausgibt, die Rückfrage dort aber gebraucht wird.
 */
(function () {
  'use strict';

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var frage = form && form.getAttribute ? form.getAttribute('data-confirm') : null;

    if (frage && !window.confirm(frage)) {
      event.preventDefault();
    }
  });
})();
