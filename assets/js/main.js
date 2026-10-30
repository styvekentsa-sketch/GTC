/**
 * Global Trading Corporation — Scripts principaux
 * 1. Menu mobile (burger)
 * 2. Sélecteur de langue (Google Translate)
 * 3. Calculateur de devises & fenêtre modale du simulateur
 * 4. Accès discret à l'Espace personnel (Dashboard)
 */

/* ==========================================================================
   1. Menu mobile
   ========================================================================== */
(function () {
  'use strict';

  var burger = document.getElementById('burger');
  var nav = document.getElementById('main-nav');
  if (!burger || !nav) return;

  var DESKTOP_BREAKPOINT = 992; // doit correspondre à la media query du CSS

  function setMenu(open) {
    nav.classList.toggle('is-open', open);
    burger.classList.toggle('is-active', open);
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
    document.body.classList.toggle('nav-open', open);
  }

  function isOpen() {
    return nav.classList.contains('is-open');
  }

  // Ouvrir / fermer au clic sur le burger
  burger.addEventListener('click', function () {
    setMenu(!isOpen());
  });

  // Fermer au clic sur un lien du menu
  nav.addEventListener('click', function (event) {
    if (event.target.closest('a')) setMenu(false);
  });

  // Fermer avec la touche Échap
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isOpen()) {
      setMenu(false);
      burger.focus();
    }
  });

  // Fermer au clic en dehors du menu
  document.addEventListener('click', function (event) {
    if (isOpen() && !nav.contains(event.target) && !burger.contains(event.target)) {
      setMenu(false);
    }
  });

  // Réinitialiser si on repasse en affichage bureau
  window.addEventListener('resize', function () {
    if (window.innerWidth >= DESKTOP_BREAKPOINT && isOpen()) setMenu(false);
  });
})();

/* ==========================================================================
   2. Sélecteur de langue (Google Translate)
   --------------------------------------------------------------------------
   - Le sélecteur est injecté dans la barre supérieure : header.php reste intact.
   - Le script Google n'est chargé que si une langue étrangère est choisie
     (aucun impact sur la vitesse pour les visiteurs francophones).
   - La langue choisie est mémorisée via le cookie "googtrans" (géré par Google).
   ========================================================================== */
(function () {
  'use strict';

  var PAGE_LANG = 'fr';
  var LANGUAGES = [
    { code: 'fr',    label: 'Français' },
    { code: 'en',    label: 'English' },
    { code: 'es',    label: 'Español' },
    { code: 'pt',    label: 'Português' },
    { code: 'de',    label: 'Deutsch' },
    { code: 'ar',    label: 'العربية' },
    { code: 'zh-CN', label: '中文' }
  ];

  var host = document.querySelector('.topbar__inner');
  if (!host) return;

  // Le nom de marque ne doit jamais être traduit
  document.querySelectorAll('.logo').forEach(function (el) {
    el.classList.add('notranslate');
    el.setAttribute('translate', 'no');
  });

  /* ---------- Cookie googtrans ---------- */
  function getCurrentLang() {
    var match = document.cookie.match(/(?:^|;\s*)googtrans=\/[^/]*\/([^;]+)/);
    return match ? decodeURIComponent(match[1]) : PAGE_LANG;
  }

  function setCookie(value) {
    var domains = ['', location.hostname, '.' + location.hostname.replace(/^www\./, '')];
    domains.forEach(function (d) {
      var domainAttr = d ? '; domain=' + d : '';
      document.cookie = value
        ? 'googtrans=' + value + '; path=/' + domainAttr
        : 'googtrans=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + domainAttr;
    });
  }

  /* ---------- Construction du sélecteur ---------- */
  var current = getCurrentLang();

  var wrapper = document.createElement('div');
  wrapper.className = 'lang-switch notranslate';
  wrapper.setAttribute('translate', 'no');
  wrapper.innerHTML =
    '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/>' +
    '<path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"/></svg>' +
    '<label for="lang-select" class="visually-hidden">Langue</label>' +
    '<select id="lang-select" class="lang-switch__select">' +
    LANGUAGES.map(function (l) {
      return '<option value="' + l.code + '"' + (l.code === current ? ' selected' : '') + '>' + l.label + '</option>';
    }).join('') +
    '</select>';
  host.appendChild(wrapper);

  var gtContainer = document.createElement('div');
  gtContainer.id = 'google_translate_element';
  document.body.appendChild(gtContainer);

  /* ---------- Chargement différé du widget Google ---------- */
  var scriptLoaded = false;

  window.googleTranslateElementInit = function () {
    new google.translate.TranslateElement({
      pageLanguage: PAGE_LANG,
      includedLanguages: LANGUAGES.map(function (l) { return l.code; }).join(','),
      autoDisplay: false
    }, 'google_translate_element');
  };

  function loadGoogleTranslate() {
    if (scriptLoaded) return;
    scriptLoaded = true;
    var s = document.createElement('script');
    s.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
    s.async = true;
    document.body.appendChild(s);
  }

  // Une langue étrangère est mémorisée : on charge le widget pour traduire la page
  if (current !== PAGE_LANG) loadGoogleTranslate();

  /* ---------- Changement de langue ---------- */
  wrapper.querySelector('select').addEventListener('change', function (event) {
    var lang = event.target.value;

    // Retour au français : suppression du cookie et rechargement
    if (lang === PAGE_LANG) {
      setCookie(null);
      location.reload();
      return;
    }

    setCookie('/' + PAGE_LANG + '/' + lang);

    var combo = document.querySelector('.goog-te-combo');
    if (combo) {
      // Widget déjà prêt : traduction instantanée sans rechargement
      combo.value = lang;
      combo.dispatchEvent(new Event('change'));
    } else if (scriptLoaded) {
      location.reload();
    } else {
      // Premier choix : le widget lit le cookie et traduit à son chargement
      loadGoogleTranslate();
    }
  });
})();

/* ==========================================================================
   3. Calculateur de devises & fenêtre modale du simulateur
   --------------------------------------------------------------------------
   - Tout élément .open-calculator ouvre la modale #calculator-modal.
   - Sur une page sans modale (ex. accueil), le lien suit son href
     (services.php#simulateur) et la modale s'ouvre à l'arrivée.
   - data-fx-pick="USD" présélectionne la devise de départ.
   ========================================================================== */
(function () {
  'use strict';

  /* ---------- Calculateur de devises ---------- */
  var fx = document.getElementById('fx');
  var amount, from, to, update;

  if (fx) {
    // Taux de la grille de l'entreprise : { EUR: { buy, sell, decimals }, … } (FCFA : 1 / 1)
    var rates   = JSON.parse(fx.getAttribute('data-rates') || '{}');
    var waPhone = fx.getAttribute('data-wa');
    var api     = fx.getAttribute('data-api');
    var result  = document.getElementById('fx-result');
    var rateEl  = document.getElementById('fx-rate');
    var cta     = document.getElementById('fx-cta');
    var swap    = document.getElementById('fx-swap');
    amount = document.getElementById('fx-amount');
    from   = document.getElementById('fx-from');
    to     = document.getElementById('fx-to');

    var format = function (value, code) {
      var d = (rates[code] && rates[code].decimals) || 0;
      return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }).format(value) + '\u00A0' + code;
    };

    update = function () {
      var a = parseFloat(String(amount.value).replace(',', '.'));
      var valid = isFinite(a) && a >= 0;
      // Le guichet achète la devise remise (taux d'achat) et vend la devise demandée (taux de vente)
      var rate = from.value === to.value ? 1 : rates[from.value].buy / rates[to.value].sell;
      var converted = valid ? a * rate : 0;

      result.textContent = valid ? format(converted, to.value) : '—';
      // Taux au dix-millième près, dans le sens lisible : « 1 EUR ≈ 655,957 FCFA » aussi pour FCFA → EUR
      // (plutôt que « 1 FCFA ≈ 0,0015 EUR ») ; même règle que fx_rate_text() dans services.php
      var fmtRate = function (r) { return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 4 }).format(r); };
      rateEl.textContent = rate > 0 && rate < 1
        ? '1 ' + to.value + ' ≈ ' + fmtRate(1 / rate) + '\u00A0' + from.value
        : '1 ' + from.value + ' ≈ ' + fmtRate(rate) + '\u00A0' + to.value;

      var message = 'Bonjour GTC, je souhaite connaître le taux du jour pour convertir ' +
        (valid ? format(a, from.value) : 'un montant en ' + from.value) + ' en ' + to.value +
        (valid ? ' (estimation sur votre site : ' + format(converted, to.value) + ').' : '.');
      cta.href = 'https://wa.me/' + waPhone + '?text=' + encodeURIComponent(message);

      result.classList.remove('is-updated');
      void result.offsetWidth;
      result.classList.add('is-updated');
    };

    [amount, from, to].forEach(function (el) { el.addEventListener('input', update); });

    swap.addEventListener('click', function () {
      var tmp = from.value;
      from.value = to.value;
      to.value = tmp;
      swap.classList.toggle('is-flipped');
      update();
    });

    update();

    // Taux à jour (api-rates.php) : une modification de la grille dans le Dashboard s'applique sans recharger la page
    var refreshRates = function () {
      if (!api || !window.fetch) return;
      fetch(api, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) {
          if (!d || !d.rates || !d.rates[from.value] || !d.rates[to.value]) return;
          rates = d.rates;
          var stamp = document.getElementById('fx-updated');
          if (stamp && d.updated) stamp.textContent = d.updated;
          update();
        })
        .catch(function () { /* taux de la page conservés */ });
    };
    refreshRates.done = false;
    fx.refreshRates = function () {
      if (refreshRates.done) return;
      refreshRates.done = true;
      refreshRates();
    };
  }

  /* ---------- Fenêtre modale ---------- */
  var modal = document.getElementById('calculator-modal');
  if (!modal) return; // pas de modale sur cette page : les liens .open-calculator suivent leur href

  var dialog = modal.querySelector('.modal__dialog');
  var lastTrigger = null;
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function openModal(trigger) {
    lastTrigger = trigger || document.activeElement;
    if (fx && fx.refreshRates) fx.refreshRates();

    // Présélection de la devise (boutons « Simuler » de la liste des devises)
    var pick = trigger && trigger.getAttribute('data-fx-pick');
    if (pick && from && to) {
      from.value = pick;
      to.value = pick === 'FCFA' ? 'EUR' : 'FCFA';
      update();
    }

    // Verrouillage du défilement sans décalage de la page
    var scrollbar = window.innerWidth - document.documentElement.clientWidth;
    document.body.style.paddingRight = scrollbar > 0 ? scrollbar + 'px' : '';
    document.body.classList.add('modal-open');

    modal.hidden = false;
    requestAnimationFrame(function () { modal.classList.add('is-open'); });

    setTimeout(function () {
      if (amount) { amount.focus(); amount.select(); } else { dialog.focus(); }
    }, 50);
  }

  function closeModal() {
    if (modal.hidden) return;
    modal.classList.remove('is-open');
    document.body.classList.remove('modal-open');
    document.body.style.paddingRight = '';
    setTimeout(function () { modal.hidden = true; }, 200);

    if (location.hash === '#simulateur') {
      history.replaceState(null, '', location.pathname + location.search);
    }
    if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
  }

  // Ouverture : délégation pour couvrir tous les boutons .open-calculator
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('.open-calculator');
    if (trigger) {
      e.preventDefault();
      openModal(trigger);
    }
  });

  // Fermeture : croix et clic sur l'arrière-plan
  modal.querySelectorAll('[data-modal-close]').forEach(function (el) {
    el.addEventListener('click', closeModal);
  });

  // Clavier : Échap ferme, Tab reste dans la modale
  document.addEventListener('keydown', function (e) {
    if (modal.hidden) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      closeModal();
    } else if (e.key === 'Tab') {
      var items = Array.prototype.filter.call(dialog.querySelectorAll(FOCUSABLE), function (el) {
        return el.offsetParent !== null;
      });
      if (!items.length) return;
      var first = items[0];
      var last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  // Arrivée depuis un lien services.php#simulateur
  if (location.hash === '#simulateur') openModal(null);
})();

/* ==========================================================================
   4. Accès discret à l'Espace personnel (Dashboard)
   --------------------------------------------------------------------------
   Rien de visible pour les visiteurs. Trois accès :
   - le symbole « © » du pied de page (lien au style neutre) ;
   - Ctrl + Maj + A, ou la séquence tapée « gtcadmin » (secours : certains
     navigateurs réservent Ctrl + Maj + A à leurs propres fonctions) ;
   - un triple-clic rapide sur le logo du pied de page.
   Ignoré lorsque l'on écrit dans un champ de formulaire.
   ========================================================================== */
(function () {
  'use strict';

  // Adresse prise sur le lien « Espace Collaborateur » du pied de page (correcte depuis tout dossier)
  var staffLink = document.querySelector('.site-footer__staff');
  var LOGIN_URL = staffLink ? staffLink.href : new URL('dashboard/login.php', document.baseURI).href;
  var SEQUENCE  = 'gtcadmin';
  var typed     = '';

  function goToLogin() {
    window.location.href = LOGIN_URL;
  }

  function isTyping(el) {
    return el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
  }

  /* ---------- Raccourci clavier & séquence ---------- */
  document.addEventListener('keydown', function (e) {
    if (isTyping(e.target)) return;

    if (e.ctrlKey && e.shiftKey && !e.altKey && (e.key === 'A' || e.key === 'a')) {
      e.preventDefault();
      goToLogin();
      return;
    }

    if (typeof e.key === 'string' && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
      typed = (typed + e.key.toLowerCase()).slice(-SEQUENCE.length);
      if (typed === SEQUENCE) {
        typed = '';
        goToLogin();
      }
    }
  });

  /* ---------- Triple-clic sur le logo du pied de page ---------- */
  var logo = document.querySelector('[data-secret-logo]');
  if (!logo) return;

  var clicks = 0;
  var timer = null;
  var DELAY = 400; // ms entre deux clics

  logo.addEventListener('click', function (e) {
    // Clic modifié (nouvel onglet, etc.) : comportement normal du lien
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;

    e.preventDefault();
    clicks++;
    clearTimeout(timer);

    if (clicks >= 3) {
      clicks = 0;
      goToLogin();
      return;
    }
    // Simple clic : le lien vers l'accueil fonctionne normalement après un court délai
    timer = setTimeout(function () {
      clicks = 0;
      window.location.href = logo.href;
    }, DELAY);
  });
})();
