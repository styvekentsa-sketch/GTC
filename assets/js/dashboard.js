/**
 * Global Trading Corporation — Scripts du Dashboard
 * 1. Barre latérale mobile
 * 2. Confirmation des actions sensibles  (data-confirm)
 * 3. Envoi automatique des listes        (data-autosubmit)
 * 4. Facture : calcul du montant à recevoir
 * 5. Masquage automatique des messages   (data-autohide)
 * 6. Afficher / masquer les mots de passe
 * 7. Onglets de la page Paramètres
 * 8. Taux de change de l'entreprise : écart, lignes modifiées, import du marché
 * 9. Plusieurs onglets : avertissement si le compte change dans un autre onglet
 */
(function () {
  'use strict';

  /* ---------- 1. Barre latérale mobile ---------- */
  var sidebar  = document.getElementById('sidebar');
  var toggle   = document.getElementById('sidebar-toggle');
  var backdrop = document.getElementById('sidebar-backdrop');

  function setSidebar(open) {
    if (!sidebar || !toggle) return;
    sidebar.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    if (backdrop) backdrop.hidden = !open;
  }

  if (toggle) {
    toggle.addEventListener('click', function () {
      setSidebar(!sidebar.classList.contains('is-open'));
    });
  }
  if (backdrop) backdrop.addEventListener('click', function () { setSidebar(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') setSidebar(false);
  });
  window.addEventListener('resize', function () {
    if (window.innerWidth > 1024) setSidebar(false);
  });

  // Menu de la page en cours toujours visible dans la liste (qui défile sur les écrans peu hauts)
  // (repris au chargement complet : police et logo modifient encore la hauteur des menus)
  var activeLink = sidebar && sidebar.querySelector('.sidebar__nav .sidebar__link.is-active');
  function revealActive() {
    var nav = activeLink.parentNode;
    var top = activeLink.offsetTop - nav.offsetTop;
    if (top + activeLink.offsetHeight > nav.scrollTop + nav.clientHeight || top < nav.scrollTop) {
      nav.scrollTop = top - (nav.clientHeight - activeLink.offsetHeight) / 2;
    }
  }
  if (activeLink) {
    revealActive();
    window.addEventListener('load', revealActive);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(revealActive);
  }

  /* ---------- 2. Confirmation ---------- */
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });

  /* ---------- 3. Envoi automatique ---------- */
  document.querySelectorAll('[data-autosubmit]').forEach(function (select) {
    select.addEventListener('change', function () {
      if (select.form) select.form.requestSubmit ? select.form.requestSubmit() : select.form.submit();
    });
  });

  /* ---------- 4. Facture : calcul automatique du montant à recevoir ---------- */
  var invForm = document.getElementById('invoice-form');
  if (invForm) {
    var rates    = JSON.parse(invForm.getAttribute('data-rates') || '{}');      // grille GTC : { EUR: { buy, sell } } en FCFA
    var decimals = JSON.parse(invForm.getAttribute('data-decimals') || '{}');
    var fAmount  = document.getElementById('amount_given');
    var fSource  = document.getElementById('source_currency');
    var fTarget  = document.getElementById('target_currency');
    var fRate    = document.getElementById('exchange_rate');
    var fDir     = document.getElementById('rate_direction');
    var fFees    = document.getElementById('fees');
    var outNet   = document.getElementById('net-amount');
    var outInfo  = document.getElementById('net-detail');
    var rateTouched = fRate.value !== '';

    var label = function (code) { return code === 'XAF' ? 'FCFA' : code; };
    var num = function (v) {
      var n = parseFloat(String(v).replace(/[\s  ]/g, '').replace(',', '.'));
      return isFinite(n) ? n : NaN;
    };
    var fmt = function (v, code) {
      var d = decimals[code] != null ? decimals[code] : 2;
      return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }).format(v) + ' ' + label(code);
    };
    var fmtRate = function (r) {
      return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 4 }).format(r);
    };

    // Libellés du sens du taux avec les devises choisies : « 1 EUR = taux FCFA »
    var updateDirectionLabels = function () {
      var s = label(fSource.value), t = label(fTarget.value);
      fDir.options[0].text = '1 ' + s + ' = taux ' + t + ' (×)';
      fDir.options[1].text = '1 ' + t + ' = taux ' + s + ' (÷)';
    };

    var compute = function () {
      updateDirectionLabels();
      var a = num(fAmount.value), r = num(fRate.value);
      var f = fFees && fFees.value !== '' ? num(fFees.value) : 0;
      if (!(a > 0) || !(r > 0) || isNaN(f) || f < 0 || f >= a) {
        outNet.textContent = '—';
        outInfo.textContent = 'Saisissez le montant versé et le taux.';
        return;
      }
      var d = decimals[fTarget.value] != null ? decimals[fTarget.value] : 2;
      var divide = fDir.value === 'divide';
      var net = a - f;   // commission prélevée avant conversion (devise source)
      var received = Math.round((divide ? net / r : net * r) * Math.pow(10, d)) / Math.pow(10, d);
      outNet.textContent = fmt(received, fTarget.value);
      outInfo.textContent = (f > 0 ? '(' + fmt(a, fSource.value) + ' − ' + fmt(f, fSource.value) + ')' : fmt(a, fSource.value)) +
        (divide ? ' ÷ ' : ' × ') + fmtRate(r) + ' = ' + fmt(received, fTarget.value);
    };

    // Badge d'état du taux : grille de l'entreprise, ou taux saisi par l'agent
    var badge   = document.getElementById('rate-badge');
    var api     = invForm.getAttribute('data-rates-api');
    var ratesUpdated = invForm.getAttribute('data-rates-updated') || '';
    var setBadge = function (kind, text) {
      if (!badge) return;
      badge.className = 'rate-badge rate-badge--' + kind;
      badge.textContent = text;
    };
    var applyRate = function (r, direction) {
      fDir.value = direction;
      fRate.value = String(Math.round(r * 10000) / 10000).replace('.', ',');
      compute();
    };

    // Taux de la grille chargée avec le formulaire, même règle que le serveur (includes/company_rates.php) :
    // achat de la devise remise ÷ vente de la devise reçue, présenté dans le sens le plus lisible (taux ≥ 1)
    var localRate = function () {
      if (fSource.value === fTarget.value) return { rate: 1, direction: 'multiply' };
      var s = rates[fSource.value], t = rates[fTarget.value];
      var r = s && t ? s.buy / t.sell : NaN;
      if (!isFinite(r) || r <= 0) return null;
      return r >= 1 ? { rate: r, direction: 'multiply' } : { rate: 1 / r, direction: 'divide' };
    };
    var companyBadge = function (updated) {
      setBadge('live', 'Taux de l\'entreprise' + (updated ? ' (grille du ' + updated + ')' : ''));
    };

    // Taux de la grille à jour (dashboard/rates-api.php), sinon celle chargée avec la page
    var requestId = 0;
    var suggestRate = function () {
      var id = ++requestId;
      var fallback = function () {
        var l = localRate();
        if (l) applyRate(l.rate, l.direction); else compute();
        companyBadge(ratesUpdated);
      };
      if (!api || fSource.value === fTarget.value) { fallback(); return; }
      setBadge('loading', 'Récupération du taux de l\'entreprise…');
      fetch(api + '?from=' + encodeURIComponent(fSource.value) + '&to=' + encodeURIComponent(fTarget.value),
        { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
        .then(function (d) {
          if (id !== requestId || rateTouched) return;   // réponse dépassée, ou l'agent a déjà saisi un taux
          applyRate(d.rate, d.direction);
          companyBadge(d.updated);
        })
        .catch(function () { if (id === requestId && !rateTouched) fallback(); });
    };

    fRate.addEventListener('input', function () {
      rateTouched = true;
      requestId++;   // une réponse encore attendue ne doit pas écraser la saisie
      setBadge('custom', 'Taux personnalisé (négocié ou avec marge spécifique)');
      compute();
    });
    fAmount.addEventListener('input', compute);
    if (fFees) fFees.addEventListener('input', compute);
    fDir.addEventListener('change', compute);
    [fSource, fTarget].forEach(function (el) {
      el.addEventListener('change', function () {
        // Nouvelle paire de devises : le taux précédent ne s'applique plus, le taux de la grille est proposé
        rateTouched = false;
        suggestRate();
      });
    });
    document.getElementById('rate-suggest').addEventListener('click', function () {
      rateTouched = false;
      suggestRate();
    });

    if (fRate.value === '') {
      suggestRate();
    } else {
      // Taux déjà présent (formulaire renvoyé, ou repris du simulateur)
      rateTouched = true;
      setBadge('custom', 'Taux repris de la saisie : « Taux GTC » pour le taux de la grille');
      compute();
    }
  }

  /* ---------- 6. Afficher / masquer les mots de passe ---------- */
  // Chaque champ type="password" reçoit un bouton « œil » (connexion, installation,
  // changement de mot de passe, création de comptes…), sans modifier le HTML des pages.
  var EYE     = '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/></svg>';

  document.querySelectorAll('input[type="password"]').forEach(function (input) {
    var wrapper = document.createElement('span');
    wrapper.className = 'password-field';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'password-toggle';
    btn.setAttribute('aria-pressed', 'false');
    btn.setAttribute('aria-label', 'Afficher le mot de passe');
    btn.title = 'Afficher le mot de passe';
    btn.innerHTML = EYE;
    wrapper.appendChild(btn);

    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? EYE_OFF : EYE;
      btn.setAttribute('aria-pressed', String(show));
      btn.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
      btn.title = btn.getAttribute('aria-label');
      // Le curseur reste dans le champ, à la fin du texte
      input.focus();
      var end = input.value.length;
      try { input.setSelectionRange(end, end); } catch (e) { /* champ sans sélection */ }
    });

    // Sécurité : le mot de passe est toujours masqué au moment de l'envoi du formulaire
    if (input.form) {
      input.form.addEventListener('submit', function () {
        input.type = 'password';
      });
    }
  });

  /* ---------- 7. Onglets de la page Paramètres ---------- */
  var settings = document.querySelector('[data-settings-tabs]');
  if (settings) {
    var tabLinks = Array.prototype.slice.call(settings.querySelectorAll('[role="tab"]'));
    var panels = tabLinks.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });

    // Panneaux « panel-<nom> », adresse « #<nom> » : pas de saut de défilement natif du navigateur
    var tabName = function (t) { return t.getAttribute('aria-controls').replace(/^panel-/, ''); };

    var showTab = function (name, updateHash) {
      var index = tabLinks.findIndex(function (t) { return tabName(t) === name; });
      if (index < 0) index = 0;
      tabLinks.forEach(function (t, i) {
        var active = i === index;
        t.classList.toggle('is-active', active);
        t.setAttribute('aria-selected', String(active));
        t.tabIndex = active ? 0 : -1;
        panels[i].hidden = !active;
      });
      if (updateHash) history.replaceState(null, '', '#' + tabName(tabLinks[index]));
    };

    tabLinks.forEach(function (tab, i) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        showTab(tabName(tab), true);
      });
      tab.addEventListener('keydown', function (e) {
        var step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[e.key];
        if (!step) return;
        e.preventDefault();
        var next = tabLinks[(i + step + tabLinks.length) % tabLinks.length];
        showTab(tabName(next), true);
        next.focus();
      });
    });

    // Onglet à afficher : erreur de formulaire (data-active), adresse (#taux…), sinon le premier
    showTab(settings.getAttribute('data-active') || location.hash.slice(1), false);
  }

  /* ---------- 8. Taux de change de l'entreprise (dashboard/rates.php) ---------- */
  // Écart achat / vente en direct, lignes modifiées signalées, import des cours du marché avec la marge.
  var ratesForm = document.querySelector('[data-company-rates]');
  if (ratesForm) {
    var rNum = function (v) {
      var n = parseFloat(String(v).replace(/[\s  ]/g, '').replace(',', '.'));
      return isFinite(n) ? n : NaN;
    };
    var rStr = function (v) { return String(Math.round(v * 1e6) / 1e6).replace('.', ','); };
    var pending = ratesForm.querySelector('[data-rates-pending]');
    var rows = ratesForm.querySelectorAll('[data-rate-row]');

    var refreshRow = function (row) {
      var buy = row.querySelector('[data-rate-buy]'), sell = row.querySelector('[data-rate-sell]');
      var b = rNum(buy.value), s = rNum(sell.value), out = row.querySelector('[data-rate-spread]');
      out.textContent = b > 0 && s > 0 ? new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format((s - b) / b * 100) + ' %' : '—';
      out.classList.toggle('is-negative', b > 0 && s > 0 && s < b);
      var changed = buy.value !== buy.getAttribute('data-initial') || sell.value !== sell.getAttribute('data-initial');
      row.classList.toggle('is-changed', changed);
    };
    var refreshAll = function () {
      rows.forEach(refreshRow);
      if (pending) pending.hidden = !ratesForm.querySelector('.is-changed');
    };
    ratesForm.addEventListener('input', refreshAll);

    var importBtn = ratesForm.querySelector('[data-rates-import]');
    if (importBtn) {
      importBtn.addEventListener('click', function () {
        var m = rNum(ratesForm.querySelector('[data-rates-margin]').value);
        m = m > 0 && m <= 20 ? m / 100 : 0;
        rows.forEach(function (row) {
          var mid = rNum(row.getAttribute('data-market'));
          if (!(mid > 0)) return;
          // Même règle que includes/company_rates.php : le client reçoit un peu moins dans les deux sens
          row.querySelector('[data-rate-buy]').value = rStr(mid * (1 - m));
          row.querySelector('[data-rate-sell]').value = rStr(mid / (1 - m));
        });
        refreshAll();
      });
    }
    refreshAll();
  }

  /* ---------- 9. Plusieurs onglets : changement de compte dans un autre onglet ---------- */
  // Tous les onglets partagent la même session (un seul cookie). Si un autre onglet se connecte avec
  // un autre compte, se déconnecte ou change d'identité (prise de contrôle), cet onglet est averti au lieu
  // de continuer sous une identité qui n'est plus la sienne (ses formulaires seraient de toute façon refusés).
  var sessionBody = document.body;
  if (sessionBody.hasAttribute('data-session')) {
    var SESSION_KEY = 'gtc_session_identity';
    var mine = sessionBody.getAttribute('data-session');
    var sessionWarned = false;
    try {
      localStorage.setItem(SESSION_KEY, JSON.stringify({ id: mine, name: sessionBody.getAttribute('data-session-name') || '' }));
    } catch (e) { /* stockage indisponible : pas d'avertissement entre onglets */ }

    var warnSession = function (other) {
      sessionWarned = true;
      var box = document.createElement('div');
      box.className = 'session-guard';
      box.setAttribute('role', 'alertdialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-labelledby', 'session-guard-title');
      var panel = document.createElement('div');
      panel.className = 'session-guard__panel';
      var title = document.createElement('p');
      title.id = 'session-guard-title';
      title.className = 'session-guard__title';
      title.textContent = other.id ? 'Session modifiée dans un autre onglet' : 'Vous avez été déconnecté';
      var text = document.createElement('p');
      text.textContent = other.id
        ? 'Un autre onglet utilise maintenant le compte « ' + (other.name || 'autre utilisateur') + ' ». Cette page appartient à une session qui n\'est plus active : rechargez-la avant de continuer.'
        : 'La session a été fermée dans un autre onglet. Rechargez la page pour vous reconnecter.';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn--primary';
      btn.textContent = 'Recharger la page';
      btn.addEventListener('click', function () { window.location.reload(); });
      panel.appendChild(title);
      panel.appendChild(text);
      panel.appendChild(btn);
      box.appendChild(panel);
      document.body.appendChild(box);
      btn.focus();
    };
    var checkSession = function (raw) {
      if (sessionWarned || !mine) return;   // page de connexion : rien à protéger
      var other;
      try { other = JSON.parse(raw || 'null'); } catch (e) { return; }
      if (other && other.id !== mine) warnSession(other);
    };
    window.addEventListener('storage', function (e) { if (e.key === SESSION_KEY) checkSession(e.newValue); });
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { try { checkSession(localStorage.getItem(SESSION_KEY)); } catch (e) { /* ignoré */ } }
    });
  }

  /* ---------- 5. Masquage automatique ---------- */
  document.querySelectorAll('[data-autohide]').forEach(function (alert) {
    setTimeout(function () {
      alert.classList.add('is-hidden');
      setTimeout(function () { alert.remove(); }, 450);
    }, 5000);
  });
})();
