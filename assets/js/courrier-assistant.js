/* ==========================================================================
   Assistant de rédaction des courriers (dashboard/courriers.php → dashboard/courrier-assistant.php)

   - Quatre actions : corriger, améliorer le style (ton formel), traduire en anglais, suggérer une formule de politesse.
   - Le courrier en cours (objet, formule d'appel, corps, formule de politesse) est envoyé tel qu'il est à l'écran.
   - Les propositions s'affichent champ par champ, différences surlignées (ajouts en vert, suppressions barrées) :
     l'agent accepte ou rejette chaque champ, ou tout d'un coup ; « Annuler » rétablit le texte d'avant.
   - Rien n'est enregistré par l'assistant : l'agent enregistre ou imprime ensuite, comme d'habitude.
   ========================================================================== */
(function () {
  'use strict';

  var root = document.querySelector('[data-ai-assist]');
  if (!root || root.hasAttribute('data-disabled')) return;

  var form = root.closest('form');
  var api = root.getAttribute('data-api');
  var statusEl = root.querySelector('[data-ai-status]');
  var review = root.querySelector('[data-ai-review]');
  var buttons = [].slice.call(root.querySelectorAll('[data-ai-action]'));
  var ctrl = null;

  var FIELDS = [
    ['subject', 'Objet'],
    ['salutation', 'Formule d\'appel'],
    ['body', 'Corps du courrier'],
    ['closing', 'Formule de politesse']
  ];
  var LABELS = {
    correct: 'Corrections proposées',
    formal: 'Style formel proposé',
    english: 'Version anglaise proposée',
    closing: 'Formules de politesse proposées'
  };
  var ICON = {
    check: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    x: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    undo: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-4"/></svg>',
    alert: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>'
  };

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- Lecture / écriture des champs du courrier ---------- */
  function getField(name) { var el = $(name); return el ? el.value : ''; }
  function setField(name, value) {
    var el = $(name);
    if (!el) return;
    el.value = value;
    if (name === 'body') el.dispatchEvent(new Event('rte:set'));   // l'éditeur enrichi recharge le texte
    el.dispatchEvent(new Event('input', { bubbles: true }));
    var target = name === 'body' ? (form.querySelector('.rte__area') || el) : el;
    target.classList.add('ai-applied');
    setTimeout(function () { target.classList.remove('ai-applied'); }, 1600);
  }

  // HTML du corps → texte lisible (un paragraphe par ligne) pour la comparaison
  function htmlToText(html) {
    var d = document.createElement('div');
    d.innerHTML = html;
    var out = [];
    d.querySelectorAll('p, li').forEach(function (b) {
      out.push((b.tagName === 'LI' ? '• ' : '') + b.textContent.replace(/ /g, ' ').trim());
    });
    return out.length ? out.join('\n') : d.textContent;
  }

  /* ---------- Différences mot à mot (plus longue sous-suite commune) ---------- */
  function tokens(s) { return s.match(/\n|[^\S\n]+|[^\s]+/g) || []; }
  function diffHtml(a, b) {
    var x = tokens(a), y = tokens(b);
    var n = x.length, m = y.length;
    if (n * m > 4000000) {   // très long texte : affichage simple de la proposition
      return '<ins>' + esc(b) + '</ins>';
    }
    var w = m + 1, L = new Uint32Array((n + 1) * w);
    for (var i = n - 1; i >= 0; i--) {
      for (var j = m - 1; j >= 0; j--) {
        L[i * w + j] = x[i] === y[j] ? L[(i + 1) * w + j + 1] + 1 : Math.max(L[(i + 1) * w + j], L[i * w + j + 1]);
      }
    }
    var html = '', del = '', ins = '';
    function flush() {
      if (del) html += '<del>' + esc(del) + '</del>';
      if (ins) html += '<ins>' + esc(ins) + '</ins>';
      del = ins = '';
    }
    i = 0; j = 0;
    while (i < n && j < m) {
      if (x[i] === y[j]) { flush(); html += esc(x[i]); i++; j++; }
      else if (L[(i + 1) * w + j] >= L[i * w + j + 1]) { del += x[i++]; }
      else { ins += y[j++]; }
    }
    while (i < n) del += x[i++];
    while (j < m) ins += y[j++];
    flush();
    return html;
  }

  /* ---------- Requête ---------- */
  function busy(btn, on) {
    buttons.forEach(function (b) { b.disabled = on; });
    root.classList.toggle('is-busy', on);
    if (on) {
      statusEl.hidden = false;
      statusEl.className = 'ai-assist__status';
      statusEl.innerHTML = '<span class="ai-spin" aria-hidden="true"></span><span>' + esc(btn.getAttribute('data-busy')) + '</span>'
        + '<button type="button" class="ai-link" data-ai-cancel>Annuler</button>';
      btn.classList.add('is-active');
    } else {
      buttons.forEach(function (b) { b.classList.remove('is-active'); });
    }
  }

  function showError(msg) {
    statusEl.hidden = false;
    statusEl.className = 'ai-assist__status ai-assist__status--error';
    statusEl.innerHTML = ICON.alert + '<span>' + esc(msg) + '</span>';
  }

  function run(btn) {
    var action = btn.getAttribute('data-ai-action');
    var fd = new FormData();
    fd.append('csrf_token', (form.querySelector('[name=csrf_token]') || {}).value || '');
    fd.append('action', action);
    FIELDS.forEach(function (f) { fd.append(f[0], getField(f[0])); });
    fd.append('recipient', [getField('recipient_company'), getField('recipient_name'), getField('recipient_role')].filter(Boolean).join(', '));

    review.hidden = true;
    review.innerHTML = '';
    busy(btn, true);
    ctrl = 'AbortController' in window ? new AbortController() : null;
    fetch(api, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (d) {
          if (!r.ok) throw new Error(d.error || 'L\'assistant n\'a pas pu traiter la demande.');
          return d;
        });
      })
      .then(function (d) {
        statusEl.hidden = true;
        if (d.options) renderClosings(d.options);
        else renderFields(action, d);
      })
      .catch(function (e) {
        if (e.name === 'AbortError') { statusEl.hidden = true; return; }
        showError(e.message || 'L\'assistant est indisponible pour le moment.');
      })
      .then(function () { busy(btn, false); ctrl = null; });
  }

  /* ---------- Propositions champ par champ ---------- */
  var pending = {};   // champ → texte proposé
  var undo = null;    // { champ: texte d'avant } de la dernière application

  function renderFields(action, d) {
    pending = {};
    var cards = '';
    FIELDS.forEach(function (f) {
      var name = f[0], before = getField(name), after = (d.fields || {})[name];
      if (after == null) return;
      var a = name === 'body' ? htmlToText(before) : before.trim();
      var b = name === 'body' ? htmlToText(after) : after.trim();
      if (a === b) return;
      pending[name] = after;
      cards += '<article class="ai-change" data-field="' + name + '">'
        + '<header class="ai-change__head"><strong>' + esc(f[1]) + '</strong>'
        + '<span class="ai-change__btns">'
        + '<button type="button" class="btn btn--primary btn--sm" data-ai-accept="' + name + '">' + ICON.check + ' Accepter</button>'
        + '<button type="button" class="btn btn--ghost btn--sm" data-ai-reject="' + name + '">' + ICON.x + ' Rejeter</button>'
        + '</span></header>'
        + '<div class="ai-change__diff' + (name === 'body' ? ' ai-change__diff--body' : '') + '">' + diffHtml(a, b) + '</div>'
        + '</article>';
    });
    var notes = (d.notes || []).map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('');
    var warns = (d.warnings || []).map(function (w) { return '<p class="ai-review__warn">' + ICON.alert + ' ' + esc(w) + '</p>'; }).join('');
    if (!cards) {
      review.innerHTML = '<div class="ai-review__empty">' + ICON.check + ' <span>' + (action === 'correct'
        ? 'Aucune faute détectée : le courrier peut rester tel quel.' : 'L\'assistant ne propose aucune modification.') + '</span>'
        + '<button type="button" class="ai-link" data-ai-close>Fermer</button></div>';
      review.hidden = false;
      return;
    }
    review.innerHTML = '<header class="ai-review__head"><div><h4>' + esc(LABELS[action]) + '</h4>'
      + '<p class="ai-review__legend"><ins>ajouté</ins> <del>supprimé</del> · Relisez puis acceptez ou rejetez chaque partie.</p></div>'
      + '<span class="ai-review__all"><button type="button" class="btn btn--primary btn--sm" data-ai-accept-all>' + ICON.check + ' Tout accepter</button>'
      + '<button type="button" class="btn btn--ghost btn--sm" data-ai-reject-all>' + ICON.x + ' Tout rejeter</button></span></header>'
      + warns + (notes ? '<ul class="ai-review__notes">' + notes + '</ul>' : '') + cards;
    review.hidden = false;
    review.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  function renderClosings(options) {
    var current = getField('closing').trim();
    review.innerHTML = '<header class="ai-review__head"><div><h4>' + esc(LABELS.closing) + '</h4>'
      + '<p class="ai-review__legend">Choisissez une formule : elle remplace la formule de politesse actuelle.</p></div>'
      + '<span class="ai-review__all"><button type="button" class="btn btn--ghost btn--sm" data-ai-reject-all>' + ICON.x + ' Ignorer</button></span></header>'
      + '<div class="ai-closings">' + options.map(function (o, i) {
        var same = o.text.trim() === current;
        return '<article class="ai-closing">'
          + (o.tone ? '<span class="ai-closing__tone">' + esc(o.tone) + '</span>' : '')
          + '<p class="ai-closing__text">' + esc(o.text) + '</p>'
          + '<button type="button" class="btn ' + (same ? 'btn--ghost' : 'btn--primary') + ' btn--sm" data-ai-closing="' + i + '"' + (same ? ' disabled' : '') + '>'
          + ICON.check + ' ' + (same ? 'Formule actuelle' : 'Utiliser cette formule') + '</button></article>';
      }).join('') + '</div>';
    review._options = options;
    review.hidden = false;
    review.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  function apply(changes) {
    var names = Object.keys(changes);
    if (!names.length) return;
    undo = {};
    names.forEach(function (n) { undo[n] = getField(n); setField(n, changes[n]); });
    statusEl.hidden = false;
    statusEl.className = 'ai-assist__status ai-assist__status--done';
    statusEl.innerHTML = ICON.check + '<span>' + (names.length > 1 ? 'Modifications appliquées' : 'Modification appliquée')
      + ' au courrier. Pensez à enregistrer.</span><button type="button" class="ai-link" data-ai-undo>' + ICON.undo + ' Annuler</button>';
  }

  function dropCard(name) {
    delete pending[name];
    var card = review.querySelector('[data-field="' + name + '"]');
    if (card) card.remove();
    if (!review.querySelector('.ai-change')) { review.hidden = true; review.innerHTML = ''; }
  }

  /* ---------- Événements ---------- */
  buttons.forEach(function (b) { b.addEventListener('click', function () { run(b); }); });

  root.addEventListener('click', function (e) {
    var t = e.target.closest('button');
    if (!t) return;
    if (t.hasAttribute('data-ai-cancel')) { if (ctrl) ctrl.abort(); return; }
    if (t.hasAttribute('data-ai-close')) { review.hidden = true; return; }
    if (t.hasAttribute('data-ai-undo')) {
      if (undo) Object.keys(undo).forEach(function (n) { setField(n, undo[n]); });
      undo = null;
      statusEl.className = 'ai-assist__status';
      statusEl.innerHTML = ICON.undo + '<span>Texte d\'origine rétabli.</span>';
      return;
    }
    if (t.hasAttribute('data-ai-accept')) {
      var n = t.getAttribute('data-ai-accept'), one = {};
      one[n] = pending[n];
      apply(one);
      dropCard(n);
      return;
    }
    if (t.hasAttribute('data-ai-reject')) { dropCard(t.getAttribute('data-ai-reject')); return; }
    if (t.hasAttribute('data-ai-accept-all')) {
      apply(pending);
      pending = {};
      review.hidden = true; review.innerHTML = '';
      return;
    }
    if (t.hasAttribute('data-ai-reject-all')) { pending = {}; review.hidden = true; review.innerHTML = ''; return; }
    if (t.hasAttribute('data-ai-closing')) {
      var o = (review._options || [])[+t.getAttribute('data-ai-closing')];
      if (o) { apply({ closing: o.text }); review.hidden = true; review.innerHTML = ''; }
    }
  });

  // Propositions en attente : avertissement avant d'enregistrer sans les avoir traitées
  form.addEventListener('submit', function (e) {
    if (Object.keys(pending).length && !review.hidden
        && !window.confirm('Des propositions de l\'assistant n\'ont été ni acceptées ni rejetées. Enregistrer le courrier sans elles ?')) {
      e.preventDefault();
    }
  });
})();
