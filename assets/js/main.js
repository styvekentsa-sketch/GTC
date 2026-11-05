/**
 * Global Trading Corporation — Scripts principaux
 * 1. Menu mobile (burger)
 * 2. Sélecteur de langue (Google Translate)
 * 3. Calculateur de devises & fenêtre modale du simulateur
 * 4. Accès discret à l'Espace personnel (Dashboard)
 * 5. Carrousel des services (défilement automatique + manuel)
 * 6. Catalogue des services (apparition au défilement)
 * 7. Questions fréquentes (recherche, thèmes)
 * 8. Bouton « retour en haut »
 * 9. Taux du jour (actualisation automatique)
 * 10. Avis clients (étoiles, envoi, « Afficher plus »)
 * 11. Visite guidée « Donner mon avis » (bandeau réductible)
 * 12. Tableaux des pages légales en fiches (téléphone)
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

    // Montant estimé toujours lisible en entier : la taille du texte diminue si le montant est trop long pour la case
    // (petits écrans, grands montants), jusqu'à 12 px au plus petit ; recalculée quand la case change de taille.
    var fitResult = function () {
      result.style.fontSize = '';
      if (!result.clientWidth) return;   // fenêtre fermée : ajusté à l'ouverture (ResizeObserver)
      var size = parseFloat(getComputedStyle(result).fontSize);
      while (result.scrollWidth > result.clientWidth + 1 && size > 12) {
        size -= 1;
        result.style.fontSize = size + 'px';
      }
    };
    if (window.ResizeObserver) new ResizeObserver(function () { fitResult(); }).observe(result);

    update = function () {
      var a = parseFloat(String(amount.value).replace(',', '.'));
      var valid = isFinite(a) && a >= 0;
      // Le guichet achète la devise remise (taux d'achat) et vend la devise demandée (taux de vente)
      var rate = from.value === to.value ? 1 : rates[from.value].buy / rates[to.value].sell;
      var converted = valid ? a * rate : 0;

      result.textContent = valid ? format(converted, to.value) : '—';
      fitResult();
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

/* ==========================================================================
   5. Carrousel des services (includes/services-carousel.php)
   Défilement hybride :
   - manuel : glisser à la souris, balayage au doigt, molette / pavé tactile, boutons, points ; les cartes
     s'alignent à chaque arrêt (scroll-snap, CSS) ;
   - automatique : une carte toutes les AUTOPLAY_DELAY ms, retour au début après la dernière.
   Pause dès que le visiteur survole (mouseenter), touche (touchstart), fait défiler, glisse, utilise un bouton
   ou le clavier ; reprise RESUME_DELAY ms après qu'il a quitté le carrousel (mouseleave / touchend / fin du
   défilement / perte du focus). Bouton « Pause » : arrêt jusqu'à « Lecture » (accessibilité, WCAG 2.2.2).
   Jamais de défilement automatique si le visiteur a demandé moins d'animations, ni hors écran / onglet masqué.
   ========================================================================== */
(function () {
  'use strict';

  var AUTOPLAY_DELAY = 3000;   // ms entre deux cartes
  var RESUME_DELAY = 3000;     // ms avant la reprise, après la dernière interaction
  var DRAG_THRESHOLD = 6;      // px : en deçà, un clic reste un clic (liens, boutons des cartes)
  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.querySelectorAll('[data-svc-carousel]').forEach(function (root) {
    var track = root.querySelector('[data-svc-track]');
    var cards = Array.prototype.slice.call(track.children);
    var controls = root.querySelector('[data-svc-controls]');
    var dots = Array.prototype.slice.call(root.querySelectorAll('[data-svc-dot]'));
    var toggle = root.querySelector('[data-svc-toggle]');
    var toggleLabel = root.querySelector('[data-svc-toggle-label]');
    if (!cards.length) return;
    controls.hidden = false;

    var timer = null;          // setInterval du défilement automatique
    var resumeTimer = null;    // setTimeout de reprise
    var hovering = false, touching = false, dragging = false, focused = false;
    var stoppedByUser = reducedMotion;   // bouton « Pause » (ou moins d'animations demandé)
    var visible = true;
    var programmatic = 0;      // horodatage du dernier défilement lancé par le script

    if (reducedMotion) toggle.hidden = true;

    /* ---------- Position ---------- */

    // Marge d'alignement : égale à la marge intérieure de la bande (CSS : padding et scroll-padding = --svc-pad) ;
    // lue sur padding-left, toujours converti en pixels (scroll-padding peut rester une formule max(…) non résolue)
    var padStart = function () { return parseFloat(getComputedStyle(track).paddingLeft) || 0; };
    // Carte alignée au bord gauche (la plus proche de la position actuelle)
    var currentIndex = function () {
      var x = track.scrollLeft + padStart(), best = 0, dist = Infinity;
      cards.forEach(function (c, i) {
        var d = Math.abs(c.offsetLeft - x);
        if (d < dist) { dist = d; best = i; }
      });
      return best;
    };
    var atEnd = function () { return track.scrollLeft + track.clientWidth >= track.scrollWidth - 4; };

    var goTo = function (index, smooth) {
      var i = Math.max(0, Math.min(cards.length - 1, index));
      programmatic = Date.now();
      track.scrollTo({ left: cards[i].offsetLeft - padStart(), behavior: smooth === false || reducedMotion ? 'auto' : 'smooth' });
    };
    // Suivant : retour au début après la dernière carte visible ; Précédent : depuis le début, va à la fin
    var next = function () { if (atEnd()) goTo(0); else goTo(currentIndex() + 1); };
    var prev = function () { if (track.scrollLeft <= 4) goTo(cards.length - 1); else goTo(currentIndex() - 1); };

    var updateDots = function () {
      // En fin de bande, la dernière carte peut ne pas atteindre le bord gauche : le dernier point s'allume quand même
      var i = atEnd() ? cards.length - 1 : currentIndex();
      dots.forEach(function (d, k) {
        if (k === i) d.setAttribute('aria-current', 'true'); else d.removeAttribute('aria-current');
      });
    };

    /* ---------- Défilement automatique ---------- */

    var canPlay = function () { return !stoppedByUser && !hovering && !touching && !dragging && !focused && visible && !document.hidden; };
    var stop = function () {
      clearInterval(timer); timer = null;
      clearTimeout(resumeTimer); resumeTimer = null;
    };
    var start = function () {
      stop();
      if (canPlay()) timer = setInterval(function () { if (canPlay()) next(); else stop(); }, AUTOPLAY_DELAY);
    };
    // Interaction : pause immédiate, reprise RESUME_DELAY après la fin (si le visiteur n'est plus dans le carrousel)
    var pause = function () { stop(); };
    var scheduleResume = function () {
      clearTimeout(resumeTimer);
      if (stoppedByUser) return;
      // Reprise : la carte suivante arrive RESUME_DELAY après la fin de l'interaction, puis le rythme normal reprend
      resumeTimer = setTimeout(function () { if (canPlay()) { next(); start(); } }, RESUME_DELAY);
    };

    var setStopped = function (value) {
      stoppedByUser = value;
      toggle.setAttribute('aria-pressed', String(value));
      toggleLabel.textContent = value ? 'Lecture' : 'Pause';
      if (value) stop(); else start();
    };

    /* ---------- Souris : survol (mouseenter / mouseleave) ---------- */
    // Souris réelle uniquement : sur téléphone, un simple appui simule aussi une entrée de la souris, jamais sa
    // sortie — le carrousel resterait en pause indéfiniment (le toucher est géré par touchstart / touchend)

    root.addEventListener('pointerenter', function (e) { if (e.pointerType !== 'mouse') return; hovering = true; pause(); });
    root.addEventListener('pointerleave', function (e) { if (e.pointerType !== 'mouse') return; hovering = false; if (!dragging) scheduleResume(); });

    /* ---------- Tactile : balayage natif (overflow-x) ---------- */

    track.addEventListener('touchstart', function () { touching = true; pause(); }, { passive: true });
    var touchEnd = function () { touching = false; scheduleResume(); };
    track.addEventListener('touchend', touchEnd, { passive: true });
    track.addEventListener('touchcancel', touchEnd, { passive: true });

    /* ---------- Souris : cliquer-glisser ---------- */

    var startX = 0, startScroll = 0, moved = false;
    track.addEventListener('pointerdown', function (e) {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;   // doigt et stylet : défilement natif
      dragging = true; moved = false;
      startX = e.clientX; startScroll = track.scrollLeft;
      pause();
    });
    window.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      var dx = e.clientX - startX;
      if (!moved && Math.abs(dx) < DRAG_THRESHOLD) return;
      if (!moved) { moved = true; track.classList.add('is-dragging'); }
      track.scrollLeft = startScroll - dx;
    });
    var endDrag = function () {
      if (!dragging) return;
      dragging = false;
      if (moved) {
        // Alignement rétabli sur la carte la plus proche (le défilement d'alignement n'est pas une interaction)
        var i = currentIndex();
        track.classList.remove('is-dragging');
        goTo(i);
      }
      if (!hovering) scheduleResume();
    };
    window.addEventListener('pointerup', endDrag);
    window.addEventListener('pointercancel', endDrag);
    // Un glisser ne doit pas ouvrir le lien sur lequel il s'est terminé
    track.addEventListener('click', function (e) {
      if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; }
    }, true);
    track.addEventListener('dragstart', function (e) { e.preventDefault(); });   // pas d'image « fantôme »

    /* ---------- Défilement manuel (molette, pavé tactile, clavier) ---------- */

    var scrollIdle = null;
    track.addEventListener('scroll', function () {
      updateDots();
      // Défilement lancé par le script (automatique, boutons, points) : ce n'est pas une interaction
      if (Date.now() - programmatic < 900 || dragging) return;
      pause();
      clearTimeout(scrollIdle);
      scrollIdle = setTimeout(function () { if (!hovering && !touching) scheduleResume(); }, 150);   // fin du geste
    }, { passive: true });

    /* ---------- Boutons, points, clavier ---------- */

    root.querySelector('[data-svc-prev]').addEventListener('click', function () { pause(); prev(); if (!hovering) scheduleResume(); });
    root.querySelector('[data-svc-next]').addEventListener('click', function () { pause(); next(); if (!hovering) scheduleResume(); });
    dots.forEach(function (d) {
      d.addEventListener('click', function () { pause(); goTo(parseInt(d.getAttribute('data-svc-dot'), 10)); if (!hovering) scheduleResume(); });
    });
    toggle.addEventListener('click', function () { setStopped(!stoppedByUser); });

    // Focus au clavier dans le carrousel (Tab, lecteur d'écran) : pause tant qu'il y reste. Le focus laissé par un
    // clic de souris (bouton Suivant, lien d'une carte) ne compte pas : la reprise suit alors la sortie de la souris
    var keyboardFocus = function (el) { try { return el.matches(':focus-visible'); } catch (err) { return true; } };
    root.addEventListener('focusin', function (e) {
      if (!keyboardFocus(e.target)) return;
      focused = true; pause();
      var card = e.target.closest && e.target.closest('.svc-card');
      if (card) {   // carte atteinte avec Tab : amenée à l'écran
        var r = card.getBoundingClientRect(), t = track.getBoundingClientRect();
        if (r.left < t.left || r.right > t.right) goTo(cards.indexOf(card));
      }
    });
    root.addEventListener('focusout', function (e) {
      if (!root.contains(e.relatedTarget)) { focused = false; scheduleResume(); }
    });
    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); pause(); next(); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); pause(); prev(); }
    });

    /* ---------- Hors écran / onglet masqué : aucun défilement inutile ---------- */

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        visible = entries[0].isIntersecting;
        if (visible) start(); else stop();
      }, { threshold: 0.25 }).observe(root);
    }
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });
    window.addEventListener('resize', function () { updateDots(); });

    updateDots();
    start();
  });
})();

/* ==========================================================================
   6. Catalogue des services (page Services, includes/services-catalogue.php)
   Les rangées apparaissent en douceur à l'arrivée à l'écran. Sans IntersectionObserver ou si le visiteur a demandé
   moins d'animations, rien n'est masqué (la classe is-animated n'est jamais posée).
   ========================================================================== */
(function () {
  'use strict';

  var root = document.querySelector('[data-svc-catalogue]');
  if (!root || !('IntersectionObserver' in window)) return;
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  var rows = root.querySelectorAll('.svc-row');
  root.classList.add('is-animated');
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    });
  }, { rootMargin: '0px 0px -10% 0px', threshold: 0.12 });
  rows.forEach(function (row) { observer.observe(row); });

  // Arrivée par une ancre (#transit…) ou impression : la rangée visée et les précédentes apparaissent tout de suite
  var showAll = function () { rows.forEach(function (row) { row.classList.add('is-visible'); }); };
  if (location.hash && root.querySelector(location.hash.replace(/[^#\w-]/g, ''))) showAll();
  window.addEventListener('beforeprint', showAll);
})();

/* ==========================================================================
   7. Questions fréquentes (faq.php)
   Recherche instantanée (accents et majuscules ignorés) et filtre par thème ; une question atteinte par un lien
   (faq.php#change-2) s'ouvre ; une seule réponse ouverte à la fois. Sans JavaScript : tout reste lisible.
   ========================================================================== */
(function () {
  'use strict';

  var root = document.querySelector('[data-faq]');
  if (!root) return;

  var tools = root.querySelector('[data-faq-tools]');
  var search = root.querySelector('[data-faq-search]');
  var filters = Array.prototype.slice.call(root.querySelectorAll('[data-faq-filter]'));
  var groups = Array.prototype.slice.call(root.querySelectorAll('[data-faq-group]'));
  var items = Array.prototype.slice.call(root.querySelectorAll('[data-faq-item]'));
  var empty = root.querySelector('[data-faq-empty]');
  var count = root.querySelector('[data-faq-count]');
  var theme = '';
  tools.hidden = false;

  var norm = function (s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
  };
  var texts = items.map(function (it) { return norm(it.textContent); });

  var apply = function () {
    var words = norm(search.value).split(' ').filter(Boolean);
    var shown = 0;
    groups.forEach(function (g) {
      var inTheme = !theme || g.getAttribute('data-faq-group') === theme;
      var visible = 0;
      g.querySelectorAll('[data-faq-item]').forEach(function (it) {
        var text = texts[items.indexOf(it)];
        var match = inTheme && words.every(function (w) { return text.indexOf(w) !== -1; });
        it.hidden = !match;
        if (match) visible++;
      });
      g.hidden = visible === 0;
      shown += visible;
    });
    empty.hidden = shown !== 0;
    count.textContent = words.length || theme ? shown + ' question' + (shown > 1 ? 's' : '') : '';
    // Recherche précise : la seule réponse trouvée s'ouvre d'elle-même
    if (words.length && shown === 1) {
      var only = items.filter(function (it) { return !it.hidden; })[0];
      if (!only.open) only.open = true;
    }
  };

  search.addEventListener('input', apply);
  filters.forEach(function (btn) {
    btn.addEventListener('click', function () {
      theme = btn.getAttribute('data-faq-filter');
      filters.forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
      apply();
    });
  });

  // Une seule réponse ouverte à la fois (lecture plus claire)
  items.forEach(function (it) {
    it.addEventListener('toggle', function () {
      if (!it.open) return;
      items.forEach(function (other) { if (other !== it && other.open) other.open = false; });
    });
  });

  // Lien direct vers une question : ouverte et amenée à l'écran
  var openFromHash = function () {
    var target = location.hash && document.getElementById(location.hash.slice(1));
    if (target && target.matches('[data-faq-item]')) {
      target.open = true;
      target.scrollIntoView({ block: 'center' });
    }
  };
  window.addEventListener('hashchange', openFromHash);
  openFromHash();
})();

/* ==========================================================================
   8. Bouton « retour en haut » (includes/footer.php) : affiché après un écran de défilement
   ========================================================================== */
(function () {
  'use strict';

  var btn = document.querySelector('[data-back-to-top]');
  if (!btn) return;
  var ticking = false;
  var update = function () {
    btn.hidden = window.scrollY < window.innerHeight * 0.9;
    ticking = false;
  };
  window.addEventListener('scroll', function () {
    if (!ticking) { ticking = true; requestAnimationFrame(update); }
  }, { passive: true });
  btn.addEventListener('click', function () {
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
    var target = document.getElementById('contenu') || document.body;   // le focus suit (navigation au clavier)
    target.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
  });
  update();
})();

/* ==========================================================================
   9. Taux du jour (taux.php) : grille actualisée toutes les 2 minutes sans recharger (api-rates.php)
   ========================================================================== */
(function () {
  'use strict';

  var root = document.querySelector('[data-rates-page]');
  if (!root || !window.fetch) return;
  var api = root.getAttribute('data-api');
  var label = root.querySelector('[data-rates-updated]');
  var fmt = function (v) {
    return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: v >= 100 ? 2 : 4 }).format(v);
  };

  var refresh = function () {
    if (document.hidden) return;
    fetch(api, { headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.rates) return;
        root.querySelectorAll('tr[data-code]').forEach(function (tr) {
          var r = d.rates[tr.getAttribute('data-code')];
          var unit = parseFloat(tr.getAttribute('data-unit')) || 1;
          if (!r) return;
          ['buy', 'sell'].forEach(function (side) {
            var cell = tr.querySelector('[data-side="' + side + '"]');
            var text = fmt(r[side] * unit);
            if (cell && cell.textContent !== text) {
              cell.textContent = text;
              cell.classList.remove('is-changed'); void cell.offsetWidth; cell.classList.add('is-changed');
            }
          });
        });
        if (d.updated && label.textContent !== d.updated) {
          label.textContent = d.updated;
          label.parentNode.classList.add('is-fresh');
        }
      })
      .catch(function () { /* réseau indisponible : la grille affichée reste valable */ });
  };
  setInterval(refresh, 120000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
})();

/* ==========================================================================
   10. Avis clients (avis.php) : note par étoiles, validation, envoi sans recharger (api-reviews.php),
       notification, « Afficher plus d'avis ». Sans JavaScript, le formulaire s'envoie normalement.
   ========================================================================== */
(function () {
  'use strict';

  var root = document.querySelector('[data-reviews]');
  if (!root) return;
  var api = root.getAttribute('data-api');
  var form = root.querySelector('[data-review-form]');
  var toast = document.querySelector('[data-toast]');
  var WORDS = { 1: 'Décevant', 2: 'Moyen', 3: 'Correct', 4: 'Très bien', 5: 'Excellent' };
  var LIMITS = { nameMin: 2, nameMax: 60, textMin: 10, textMax: 1000 };
  var ICON_OK = '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
  var ICON_ERR = '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>';

  /* ---------- Notification ---------- */
  var toastTimer = null;
  var showToast = function (message, ok) {
    if (!toast) return;
    toast.className = 'toast ' + (ok ? 'toast--success' : 'toast--error');
    toast.innerHTML = (ok ? ICON_OK : ICON_ERR) + '<span></span>';
    toast.querySelector('span').textContent = message;
    toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.hidden = true; }, ok ? 7000 : 6000);
  };

  if (form) {
    var word = form.querySelector('[data-rating-word]');
    var ratingBox = form.querySelector('[data-rating]');
    var textarea = form.querySelector('[data-counter]');
    var counter = form.querySelector('[data-counter-out]');
    var submit = form.querySelector('[data-review-submit]');
    var submitLabel = form.querySelector('[data-submit-label]');

    /* ---------- Étoiles : libellé de la note (survol et choix) ---------- */
    var checkedValue = function () { var c = form.querySelector('input[name=rating]:checked'); return c ? c.value : ''; };
    var setWord = function (v) { word.textContent = v ? WORDS[v] : 'Cliquez sur une étoile'; };
    form.querySelectorAll('.rating-input__stars label').forEach(function (label) {
      var value = document.getElementById(label.htmlFor).value;
      label.addEventListener('mouseenter', function () { setWord(value); });
      label.addEventListener('mouseleave', function () { setWord(checkedValue()); });
    });
    form.addEventListener('change', function (e) {
      if (e.target.name === 'rating') { setWord(e.target.value); setError('rating', ''); }
    });

    /* ---------- Compteur de caractères ---------- */
    var updateCounter = function () {
      var n = textarea.value.length;
      counter.textContent = n + ' / ' + LIMITS.textMax;
      counter.classList.toggle('is-over', n > LIMITS.textMax);
    };
    textarea.addEventListener('input', updateCounter);

    /* ---------- Erreurs par champ ---------- */
    var setError = function (field, message) {
      var out = form.querySelector('[data-error-for="' + field + '"]');
      if (out) { out.textContent = message; out.hidden = !message; }
      if (field === 'rating') ratingBox.classList.toggle('is-invalid', !!message);
      else {
        var input = form.querySelector('[name="' + field + '"]');
        if (input) { input.classList.toggle('is-invalid', !!message); input.setAttribute('aria-invalid', message ? 'true' : 'false'); }
      }
    };
    ['author_name', 'comment'].forEach(function (f) {
      form.querySelector('[name="' + f + '"]').addEventListener('input', function () { setError(f, ''); });
    });

    var validate = function () {
      var name = form.author_name.value.trim().replace(/\s+/g, ' ');
      var text = form.comment.value.trim();
      var errors = {};
      if (!checkedValue()) errors.rating = 'Choisissez une note de 1 à 5 étoiles.';
      if (name.length < LIMITS.nameMin || name.length > LIMITS.nameMax) errors.author_name = 'Indiquez votre nom ou un pseudo (' + LIMITS.nameMin + ' à ' + LIMITS.nameMax + ' caractères).';
      if (text.length < LIMITS.textMin) errors.comment = text ? 'Votre avis est trop court (' + LIMITS.textMin + ' caractères au moins).' : 'Écrivez votre avis avant de l\'envoyer.';
      else if (text.length > LIMITS.textMax) errors.comment = 'Votre avis est trop long (' + LIMITS.textMax + ' caractères au plus).';
      else if (/(https?:\/\/|www\.)/i.test(text)) errors.comment = 'Les liens ne sont pas acceptés dans un avis.';
      return errors;
    };
    var showErrors = function (errors) {
      ['rating', 'author_name', 'comment'].forEach(function (f) { setError(f, errors[f] || ''); });
      var first = Object.keys(errors)[0];
      if (first) {
        var target = first === 'rating' ? form.querySelector('input[name=rating]') : form.querySelector('[name="' + first + '"]');
        if (target) target.focus();
      }
    };

    /* ---------- Envoi sans recharger ---------- */
    var busy = false;
    form.addEventListener('submit', function (e) {
      if (!window.fetch || !window.FormData) return;   // ancien navigateur : envoi classique
      e.preventDefault();
      if (busy) return;
      var errors = validate();
      if (Object.keys(errors).length) { showErrors(errors); showToast('Merci de compléter les champs indiqués.', false); return; }
      busy = true;
      submit.classList.add('is-loading');
      submit.setAttribute('aria-busy', 'true');
      submitLabel.textContent = 'Envoi en cours…';
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Réponse inattendue du serveur. Merci de réessayer.' }; }); })
        .then(function (d) {
          if (d.ok) {
            form.reset();
            document.dispatchEvent(new CustomEvent('gtc:review-sent'));   // fin de la visite guidée (§ 11)
            setWord('');
            updateCounter();
            showErrors({});
          } else if (d.errors) {
            showErrors(d.errors);
          }
          showToast(d.message || (d.ok ? 'Merci pour votre avis !' : 'Envoi impossible.'), !!d.ok);
        })
        .catch(function () { showToast('Connexion impossible. Vérifiez votre connexion Internet puis réessayez.', false); })
        .then(function () {
          busy = false;
          submit.classList.remove('is-loading');
          submit.removeAttribute('aria-busy');
          submitLabel.textContent = 'Publier mon avis';
        });
    });
  }

  /* ---------- « Afficher plus d'avis » ---------- */
  var more = root.querySelector('[data-review-more]');
  var list = root.querySelector('[data-review-list]');
  var initials = function (name) {
    var w = name.trim().split(/\s+/).filter(Boolean);
    return ((w[0] || '').charAt(0) + (w.length > 1 ? w[w.length - 1].charAt(0) : (w[0] || '').charAt(1) || '')).toUpperCase();
  };
  var card = function (r) {
    var el = document.createElement('article');
    el.className = 'card review is-new';
    var stars = '';
    for (var i = 1; i <= 5; i++) stars += '<span class="star" style="--fill:' + (i <= r.rating ? 100 : 0) + '%" aria-hidden="true"></span>';
    el.innerHTML = '<header class="review__head"><span class="review__avatar" aria-hidden="true"></span><div><p class="review__name"></p><p class="review__date"></p></div>'
      + '<span class="stars stars--sm" role="img" aria-label="' + r.rating + ' sur 5">' + stars + '</span></header><p class="review__text"></p>';
    // Textes insérés en texte brut (jamais interprétés comme du HTML)
    el.querySelector('.review__avatar').textContent = initials(r.name);
    el.querySelector('.review__name').textContent = r.name;
    el.querySelector('.review__date').textContent = r.date;
    el.querySelector('.review__text').textContent = r.comment;
    return el;
  };
  if (more && list) {
    more.addEventListener('click', function () {
      var page = parseInt(more.getAttribute('data-next-page'), 10) || 2;
      more.disabled = true;
      more.textContent = 'Chargement…';
      fetch(api + '?page=' + page, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function (d) {
          (d.reviews || []).forEach(function (r) { list.appendChild(card(r)); });
          if (d.more) { more.setAttribute('data-next-page', String(page + 1)); more.disabled = false; more.textContent = 'Afficher plus d\'avis'; }
          else more.parentNode.remove();
        })
        .catch(function () { more.disabled = false; more.textContent = 'Réessayer'; });
    });
  }
})();

/* ==========================================================================
   11. Visite guidée « Donner mon avis » (includes/review-tour.php) : bandeau réductible (choix mémorisé pendant
       la visite), espace réservé en bas de page pour ne rien masquer, bandeau retiré une fois l'avis envoyé.
   ========================================================================== */
(function () {
  'use strict';

  var tour = document.querySelector('[data-tour]');
  if (!tour) return;
  var toggle = tour.querySelector('[data-tour-toggle]');
  var KEY = 'gtc-tour-collapsed';

  var reserve = function () { document.body.style.paddingBottom = tour.isConnected ? tour.offsetHeight + 'px' : ''; };
  var setCollapsed = function (on) {
    tour.classList.toggle('is-collapsed', on);
    toggle.setAttribute('aria-expanded', on ? 'false' : 'true');
    toggle.setAttribute('aria-label', on ? 'Afficher la visite guidée' : 'Réduire la visite guidée');
    try { sessionStorage.setItem(KEY, on ? '1' : '0'); } catch (e) { /* stockage indisponible */ }
    reserve();
  };
  var saved = null;
  try { saved = sessionStorage.getItem(KEY); } catch (e) { /* stockage indisponible */ }
  setCollapsed(saved === '1' && !tour.classList.contains('tour--last'));
  toggle.addEventListener('click', function () { setCollapsed(!tour.classList.contains('is-collapsed')); });
  window.addEventListener('resize', reserve);

  // Avis envoyé (main.js § 10) : la visite est terminée
  document.addEventListener('gtc:review-sent', function () {
    tour.remove();
    reserve();
  });
})();

/* ==========================================================================
   12. Tableaux des pages légales : sur téléphone, chaque ligne devient une fiche (style.css § 14). Le libellé de
       chaque colonne est recopié sur ses cellules (data-label) ; sans JavaScript, le tableau défile à l'horizontale.
   ========================================================================== */
(function () {
  'use strict';

  document.querySelectorAll('.legal-table').forEach(function (table) {
    var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    if (!heads.length) return;
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      Array.prototype.forEach.call(tr.children, function (cell, i) {
        if (cell.tagName === 'TD' && heads[i]) cell.setAttribute('data-label', heads[i]);
      });
    });
    table.classList.add('is-stacked');
  });
})();
