/**
 * Global Trading Corporation — Consentement aux cookies
 *
 * - Bannière #cookie-banner (includes/footer.php) affichée tant qu'aucun choix n'est enregistré.
 * - Choix mémorisé dans le navigateur : localStorage.cookie_consent = 'accepted' | 'rejected'.
 * - Services tiers soumis à consentement ([data-consent-embed], ex. carte Google Maps de la page Contact) :
 *   chargés après « Tout accepter », ou ponctuellement avec leur bouton [data-consent-load].
 * - « Gérer les cookies » ([data-cookie-settings]) rouvre la bannière ; la page Politique des cookies
 *   affiche le choix en cours ([data-cookie-status]).
 * - Événement « cookieconsent » (detail.choice) émis à chaque changement, pour d'autres scripts.
 */
(function () {
  'use strict';

  var KEY = 'cookie_consent';
  var LABELS = { accepted: 'cookies et services tiers acceptés', rejected: 'services tiers refusés' };
  var banner = document.getElementById('cookie-banner');

  var read = function () {
    try {
      var value = window.localStorage.getItem(KEY);
      return value === 'accepted' || value === 'rejected' ? value : null;
    } catch (e) {
      return null;   // stockage indisponible (navigation privée stricte…) : la bannière reste proposée
    }
  };
  var write = function (value) {
    try { window.localStorage.setItem(KEY, value); } catch (e) { /* choix appliqué pour cette page uniquement */ }
  };

  /* ---------- Services tiers soumis à consentement ---------- */
  var loadEmbed = function (box) {
    if (box.hasAttribute('data-loaded')) return;
    box.setAttribute('data-loaded', '');
    var frame = document.createElement('iframe');
    frame.src = box.getAttribute('data-src');
    frame.title = box.getAttribute('data-title') || '';
    frame.loading = 'lazy';
    frame.referrerPolicy = 'no-referrer-when-downgrade';
    frame.allowFullscreen = true;
    var placeholder = box.querySelector('.consent-embed__placeholder');
    if (placeholder) placeholder.remove();
    box.insertBefore(frame, box.firstChild);
  };

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('[data-consent-load]');
    if (trigger) {
      var box = trigger.closest('[data-consent-embed]');
      if (box) loadEmbed(box);
    }
  });

  /* ---------- Application du choix ---------- */
  var apply = function (choice) {
    document.documentElement.setAttribute('data-cookie-consent', choice || 'unset');
    if (choice === 'accepted') {
      document.querySelectorAll('[data-consent-embed]').forEach(loadEmbed);
    }
    document.querySelectorAll('[data-cookie-status-label]').forEach(function (el) {
      el.textContent = choice ? LABELS[choice] : 'aucun choix enregistré';
    });
    document.dispatchEvent(new CustomEvent('cookieconsent', { detail: { choice: choice } }));
  };

  /* ---------- Bannière ---------- */
  var showBanner = function (focus) {
    if (!banner) return;
    banner.hidden = false;
    requestAnimationFrame(function () { banner.classList.add('is-visible'); });
    // À l'ouverture demandée par l'utilisateur, le focus va au premier bouton ; jamais au chargement
    if (focus) {
      var first = banner.querySelector('button');
      if (first) first.focus();
    }
  };
  var hideBanner = function () {
    if (!banner || banner.hidden) return;
    banner.classList.remove('is-visible');
    setTimeout(function () { banner.hidden = true; }, 250);
  };

  var choose = function (choice) {
    var focusInBanner = banner && banner.contains(document.activeElement);
    write(choice);
    apply(choice);
    hideBanner();
    // Le focus ne doit pas se perdre avec la bannière qui disparaît
    if (focusInBanner) {
      var settings = document.querySelector('[data-cookie-settings]');
      if (settings) settings.focus();
    }
  };

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-cookie-accept]')) choose('accepted');
    else if (e.target.closest('[data-cookie-reject]')) choose('rejected');
    else if (e.target.closest('[data-cookie-settings]')) showBanner(true);
  });

  // Échap ferme la bannière rouverte sans modifier le choix existant
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && banner && !banner.hidden && read() !== null && banner.contains(document.activeElement)) {
      hideBanner();
      var settings = document.querySelector('[data-cookie-settings]');
      if (settings) settings.focus();
    }
  });

  var current = read();
  apply(current);
  if (current === null) showBanner(false);
})();
