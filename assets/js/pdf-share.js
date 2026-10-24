/**
 * Global Trading Corporation — Partage du PDF d'un reçu ou d'une facture depuis un téléphone
 * (dashboard/print-invoice.php, dashboard/print-commercial-invoice.php).
 *
 * Bouton [data-pdf-share] affiché seulement si l'appareil sait partager des fichiers (Android, iPhone,
 * certains ordinateurs) : le PDF (dashboard/pdf.php) est envoyé directement vers WhatsApp, e-mail, Drive…
 * Sinon, le bouton « Télécharger le PDF » reste le moyen d'obtenir le fichier.
 */
(function () {
  'use strict';

  var buttons = document.querySelectorAll('[data-pdf-share]');
  if (!buttons.length || !navigator.share || !navigator.canShare || !window.File) return;
  try {
    if (!navigator.canShare({ files: [new File(['%PDF'], 'test.pdf', { type: 'application/pdf' })] })) return;
  } catch (e) { return; }

  buttons.forEach(function (button) {
    button.hidden = false;
    button.addEventListener('click', function () {
      var url = button.getAttribute('data-pdf-url');
      var label = button.innerHTML;
      button.disabled = true;
      button.textContent = 'Préparation…';
      fetch(url + '&inline=1', { credentials: 'same-origin' })
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          var name = (r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/);
          return r.blob().then(function (blob) { return new File([blob], name ? name[1] : 'document.pdf', { type: 'application/pdf' }); });
        })
        .then(function (file) { return navigator.share({ files: [file], title: button.getAttribute('data-pdf-title') || file.name }); })
        .catch(function (err) {
          // Partage annulé par l'utilisateur : rien à faire ; autre erreur : téléchargement classique
          if (!err || err.name !== 'AbortError') window.location.href = url;
        })
        .then(function () { button.disabled = false; button.innerHTML = label; });
    });
  });
})();
