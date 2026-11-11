/* ==========================================================================
   Passerelle GTC-Vault ↔ facturation : recherche d'un client enregistré dans GTC-Vault depuis les formulaires
   de reçu de caisse (dashboard/invoices.php) et de facture commerciale (dashboard/invoices-list.php).

   - Recherche en direct (dashboard/clients-api.php, JSON) dès 2 caractères : nom, téléphone, N° de pièce.
   - Clavier : flèches haut / bas, Entrée pour choisir, Échap pour fermer (combobox ARIA).
   - Le client choisi remplit les champs du document (data-fill : { name, phone, email, address, idNumber } → id)
     et son identifiant public (uuid) part avec le formulaire ; le serveur relit le dossier (identité, état KYC).
   - Badge « Dossier KYC complet / incomplet » ; « Délier » rend le document indépendant du dossier.
   ========================================================================== */
(function () {
  'use strict';

  var root = document.querySelector('[data-kyc-link]');
  if (!root) return;

  var api     = root.getAttribute('data-api');
  var fill    = JSON.parse(root.getAttribute('data-fill') || '{}');
  var canOpen = root.hasAttribute('data-vault');
  var uuidIn  = root.querySelector('[data-kyc-uuid]');
  var box     = root.querySelector('[data-kyc-box]');
  var search  = root.querySelector('[data-kyc-search]');
  var list    = root.querySelector('[data-kyc-results]');
  var chosen  = root.querySelector('[data-kyc-chosen]');
  var form    = root.closest('form');
  var canCreate = root.hasAttribute('data-quick');                 // enregistrement rapide d'un nouveau client
  var context   = root.getAttribute('data-context') || 'receipt';  // receipt (reçu) | invoice (facture)

  var results = [];
  var active  = -1;
  var timer   = null;
  var ctrl    = null;
  var seq     = 0;

  var ICONS = {
    check: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    alert: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
    shield: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    x: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    clock: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
    plus: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>'
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // États : complet (vert), incomplet (orange), provisoire — créé depuis la caisse, à vérifier par la conformité (bleu)
  var STATES = {
    complete:   { tone: 'success', icon: 'check', short: 'KYC complet',    long: 'Dossier KYC complet' },
    incomplete: { tone: 'warning', icon: 'alert', short: 'KYC incomplet',  long: 'Dossier KYC incomplet' },
    pending:    { tone: 'info',    icon: 'clock', short: 'KYC provisoire', long: 'Dossier KYC provisoire' }
  };
  function badge(c, compact) {
    var st = STATES[c.status] || STATES.incomplete;
    var missing = c.missing && c.missing.length ? c.missing.join(', ') : '';
    var title = c.status === 'pending' ? 'Dossier créé depuis la caisse, en attente de vérification par la conformité' + (missing ? ' — manque : ' + missing : '')
      : (missing ? 'Manque : ' + missing : 'Pièces d\'identité vérifiées dans GTC-Vault');
    return '<span class="badge badge--' + st.tone + ' kyc-badge" data-kyc-badge="' + esc(c.status) + '" title="' + esc(title) + '">'
      + ICONS[st.icon] + ' ' + esc(compact ? st.short : st.long) + '</span>';
  }

  function subline(c) {
    return [c.typeLabel, c.phone, c.idNumber ? c.idLabel + ' ' + c.idNumber : ''].filter(Boolean).join(' · ');
  }

  /* ---------- Liste de résultats ---------- */
  function open(state) {
    list.hidden = !state;
    search.setAttribute('aria-expanded', state ? 'true' : 'false');
    if (!state) { active = -1; search.removeAttribute('aria-activedescendant'); }
  }

  function render(items, q) {
    results = items;
    active = -1;
    if (!items.length) {
      list.innerHTML = '<li class="kyc-link__empty" role="presentation">Aucun dossier GTC-Vault pour « ' + esc(q) + ' ».'
        + (canCreate ? '<button type="button" class="btn btn--primary btn--sm kyc-link__new" data-kyc-new>' + ICONS.plus + ' Enregistrer ce nouveau client dans GTC-Vault</button>'
          : ' Saisissez le client à la main.') + '</li>';
      open(true);
      return;
    }
    list.innerHTML = items.map(function (c, i) {
      return '<li class="kyc-link__option" role="option" id="kyc-opt-' + i + '" data-index="' + i + '" aria-selected="false">'
        + '<span class="kyc-link__who"><strong>' + esc(c.name) + '</strong><small>' + esc(subline(c)) + '</small></span>'
        + badge(c, true) + '</li>';
    }).join('');
    open(true);
  }

  function highlight(i) {
    var opts = list.querySelectorAll('[role=option]');
    if (!opts.length) return;
    active = (i + opts.length) % opts.length;
    opts.forEach(function (o, k) { o.setAttribute('aria-selected', k === active ? 'true' : 'false'); });
    search.setAttribute('aria-activedescendant', opts[active].id);
    opts[active].scrollIntoView({ block: 'nearest' });
  }

  function status(text, isError) {
    list.innerHTML = '<li class="kyc-link__empty' + (isError ? ' kyc-link__empty--error' : '') + '" role="presentation">' + esc(text) + '</li>';
    open(true);
  }

  function query(q) {
    var mine = ++seq;
    if (ctrl) ctrl.abort();
    ctrl = 'AbortController' in window ? new AbortController() : null;
    root.classList.add('is-loading');
    fetch(api + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) {
        return r.json().then(function (d) {
          if (!r.ok) throw new Error(d.error || 'Recherche indisponible.');
          return d;
        });
      })
      .then(function (d) { if (mine === seq) render(d.results || [], q); })
      .catch(function (e) { if (mine === seq && e.name !== 'AbortError') status(e.message || 'Recherche indisponible.', true); })
      .then(function () { if (mine === seq) root.classList.remove('is-loading'); });
  }

  /* ---------- Choix d'un client ---------- */
  function setField(key, value) {
    var id = fill[key];
    var el = id && document.getElementById(id);
    if (!el) return;
    value = value || '';
    // Champ sur une ligne : les retours à la ligne deviennent « , » (sinon le navigateur colle les lignes : « RueQuartier »)
    if (el.tagName === 'INPUT') value = value.split(/\s*[\r\n]+\s*/).filter(Boolean).join(', ');
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    el.removeAttribute('aria-invalid');
    el.classList.add('kyc-filled');
    setTimeout(function () { el.classList.remove('kyc-filled'); }, 1400);
  }

  function chip(c) {
    return '<div class="kyc-chip"><span class="kyc-chip__icon" aria-hidden="true">' + ICONS.shield + '</span>'
      + '<span class="kyc-chip__body"><strong data-kyc-name>' + esc(c.name) + '</strong><small>' + esc(subline(c)) + '</small></span>'
      + badge(c, false)
      + '<button type="button" class="btn btn--ghost btn--sm" data-kyc-unlink>' + ICONS.x + ' Délier</button>'
      + (c.status === 'pending' ? '<p class="kyc-chip__warn kyc-chip__warn--info">' + ICONS.clock + ' Dossier provisoire créé depuis la caisse : la conformité vérifiera'
          + (c.missing && c.missing.length ? ' et complétera les pièces (manque : ' + esc(c.missing.join(', ')) + ')' : ' les pièces') + '. Contrôlez l\'original de la pièce d\'identité.</p>'
        : c.missing && c.missing.length ? '<p class="kyc-chip__warn">' + ICONS.alert + ' Dossier incomplet — manque : ' + esc(c.missing.join(', '))
        + '. Vérifiez l\'identité du client avant de finaliser l\'opération.</p>' : '')
      + '</div>';
  }

  function choose(c) {
    uuidIn.value = c.uuid;
    setField('name', c.name);
    setField('phone', c.phone);
    setField('email', c.email);
    setField('address', c.address);
    setField('idNumber', c.idNumber);
    chosen.innerHTML = chip(c);
    chosen.hidden = false;
    box.hidden = true;
    search.value = '';
    open(false);
    var btn = chosen.querySelector('[data-kyc-unlink]');
    if (btn) btn.focus();
    root.dispatchEvent(new CustomEvent('kyc:linked', { bubbles: true, detail: c }));
  }

  function unlink() {
    uuidIn.value = '';
    chosen.innerHTML = '';
    chosen.hidden = true;
    box.hidden = false;
    search.focus();
    root.dispatchEvent(new CustomEvent('kyc:unlinked', { bubbles: true }));
  }

  /* ---------- Événements ---------- */
  search.addEventListener('input', function () {
    var q = search.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { open(false); return; }
    timer = setTimeout(function () { query(q); }, 220);
  });

  search.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden && results.length) open(true); highlight(active + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(active - 1); }
    else if (e.key === 'Enter') {
      // Entrée ne doit jamais envoyer le formulaire depuis le champ de recherche
      e.preventDefault();
      if (!list.hidden && results.length) choose(results[active >= 0 ? active : 0]);
    } else if (e.key === 'Escape') { open(false); }
  });

  list.addEventListener('mousedown', function (e) {
    if (e.target.closest('[data-kyc-new]')) { e.preventDefault(); openQuick(search.value.trim()); return; }
    var li = e.target.closest('[role=option]');
    if (!li) return;
    e.preventDefault();   // le champ garde le focus : pas de fermeture avant le choix
    choose(results[+li.getAttribute('data-index')]);
  });

  search.addEventListener('blur', function () { setTimeout(function () { open(false); }, 150); });
  search.addEventListener('focus', function () { if (search.value.trim().length >= 2 && results.length) open(true); });

  chosen.addEventListener('click', function (e) {
    if (e.target.closest('[data-kyc-unlink]')) unlink();
  });

  // Dossier déjà lié (modification, nouvel affichage après une erreur) : état du dossier relu à jour
  if (uuidIn.value) {
    fetch(api + '?uuid=' + encodeURIComponent(uuidIn.value), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.client) chosen.innerHTML = chip(d.client); })
      .catch(function () { /* la carte rendue par le serveur reste affichée */ });
  }

  /* ==========================================================================
     Nouveau client : le formulaire complet de GTC-Vault (dashboard/vault/clients-nouveau.php, mode « caisse »)
     s'ouvre dans une fenêtre — même présentation et mêmes fonctions (indicatif du pays, CNI ou passeport, pièces de
     l'entreprise, aperçus, capture par téléphone avec QR code). Le dossier créé (provisoire) ou le dossier existant
     choisi revient ici par postMessage (même origine) et est lié au document en cours.
     ========================================================================== */
  var modal = null;

  function buildModal() {
    modal = document.createElement('div');
    modal.className = 'kq-backdrop';
    modal.hidden = true;
    modal.innerHTML = '<div class="kq-dialog kq-dialog--frame" role="dialog" aria-modal="true" aria-labelledby="kq-title">'
      + '<header class="kq-head"><span class="kq-head__icon" aria-hidden="true">' + ICONS.shield + '</span><div><h2 id="kq-title">Nouveau client GTC-Vault</h2>'
      + '<p>Dossier créé en statut <strong>provisoire</strong> et lié au document en cours. La conformité vérifiera et complétera les pièces.</p></div>'
      + '<button type="button" class="kq-close" data-kq-close aria-label="Fermer">' + ICONS.x + '</button></header>'
      + '<div class="kq-frame"><div class="kq-loading" data-kq-loading><span class="kq-spin" aria-hidden="true"></span> Ouverture du formulaire GTC-Vault…</div>'
      + '<iframe title="Formulaire GTC-Vault : nouveau client" data-kq-frame></iframe></div></div>';
    document.body.appendChild(modal);
    var frame = modal.querySelector('[data-kq-frame]');
    frame.addEventListener('load', function () {
      modal.querySelector('[data-kq-loading]').hidden = true;
      // Session expirée : la fenêtre afficherait la page de connexion → rechargement de la page entière
      try { if (/\/login\.php$/.test(frame.contentWindow.location.pathname)) { location.reload(); } } catch (e) { /* autre origine : ignoré */ }
    });
    modal.addEventListener('click', function (e) {
      if (e.target === modal || e.target.closest('[data-kq-close]')) closeQuick();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && !modal.hidden) { e.preventDefault(); closeQuick(); }
    });
  }

  function openQuick(q) {
    if (!modal) buildModal();
    var frame = modal.querySelector('[data-kq-frame]');
    modal.querySelector('[data-kq-loading]').hidden = false;
    frame.src = 'vault/clients-nouveau.php?caisse=' + encodeURIComponent(context) + (q ? '&q=' + encodeURIComponent(q) : '');
    open(false);
    modal.hidden = false;
    document.documentElement.classList.add('kq-lock');
    modal.querySelector('[data-kq-close]').focus();
  }
  function closeQuick() {
    if (!modal) return;
    modal.hidden = true;
    modal.querySelector('[data-kq-frame]').src = 'about:blank';   // capture par téléphone éventuelle : annulée
    document.documentElement.classList.remove('kq-lock');
    search.focus();
  }

  // Dossier enregistré (ou dossier existant choisi) dans la fenêtre : lié au document en cours
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || !modal || modal.hidden) return;
    var d = e.data;
    if (!d || d.type !== 'gtc-vault-client' || !d.client || !d.client.uuid) return;
    var frame = modal.querySelector('[data-kq-frame]');
    if (e.source !== frame.contentWindow) return;
    closeQuick();
    choose(d.client);
  });

  // Lien permanent sous la recherche : client absent, même sans avoir cherché
  var newLink = root.querySelector('[data-kyc-new-link]');
  if (newLink && canCreate) {
    newLink.hidden = false;
    newLink.addEventListener('click', function () { openQuick(search.value.trim()); });
  }

  // Envoi : le nom saisi doit rester celui du dossier lié (sinon le lien est trompeur)
  if (form) {
    form.addEventListener('submit', function (e) {
      if (!uuidIn.value || !fill.name) return;
      var nameEl = document.getElementById(fill.name);
      var linkedName = (chosen.querySelector('[data-kyc-name]') || {}).textContent || '';
      if (nameEl && linkedName && nameEl.value.trim().toLowerCase() !== linkedName.trim().toLowerCase()) {
        if (!window.confirm('Le nom saisi (« ' + nameEl.value.trim() + ' ») diffère du dossier GTC-Vault lié (« ' + linkedName.trim() + ' »).\n\nGarder le lien avec ce dossier ?')) {
          e.preventDefault();
          unlink();
        }
      }
    });
  }
})();
