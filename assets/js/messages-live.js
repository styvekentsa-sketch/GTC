/**
 * Global Trading Corporation — Actualisation automatique de la messagerie (Dashboard)
 *
 * Chargé sur toutes les pages du Dashboard pour les comptes ayant accès aux messages
 * (includes/dashboard.php → dash_footer). Interroge dashboard/messages-api.php toutes les 6 secondes :
 *   - pastille « Messages » du menu, titre de l'onglet « (3) … », pastille du panneau de l'accueil ;
 *   - accueil : liste « Messages du site » (5 derniers non lus) remplacée quand elle change ;
 *   - page Messages (1re page de « Tous » / « Non lus », sans recherche) : la conversation qui reçoit un
 *     nouveau message remonte en tête de liste sans recharger (une seule ligne par expéditeur),
 *     compteurs des filtres, bouton « Tout marquer comme lu » ;
 *   - conversation ouverte (messages.php?id=…) : les messages du même expéditeur s'ajoutent en fin de fil ;
 *   - notification discrète « Nouveau message de … » sur les autres pages.
 *
 * Léger : pause quand l'onglet est masqué (reprise immédiate au retour), espacement progressif en cas
 * d'erreur, arrêt si la session a expiré. Aucune donnée n'est modifiée par ces requêtes (GET seulement).
 */
(function () {
  'use strict';

  var body = document.body;
  var api = body.getAttribute('data-messages-api');
  if (!api || !window.fetch) return;

  var INTERVAL = 6000;         // délai normal entre deux vérifications (ms)
  var MAX_INTERVAL = 60000;     // délai maximal après des erreurs répétées
  var lastId = parseInt(body.getAttribute('data-messages-last') || '0', 10) || 0;
  var delay = INTERVAL;
  var timer = null;
  var busy = false;
  var stopped = false;
  var baseTitle = document.title.replace(/^\(\d+\)\s*/, '');

  var live = document.querySelector('[data-messages-live]');         // liste de la page Messages
  var list = live && live.querySelector('[data-messages-list]');
  var onUnreadFilter = /[?&]f=unread\b/.test(location.search);
  var panel = document.querySelector('[data-messages-panel]');       // aperçu de l'accueil
  var thread = document.querySelector('[data-thread-live]');         // conversation ouverte

  // Fragment HTML renvoyé par le serveur (déjà échappé côté PHP) → premier élément
  var toElement = function (html) {
    var tpl = document.createElement('template');
    tpl.innerHTML = html.trim();
    return tpl.content.firstElementChild;
  };

  /* ---------- Mise à jour de l'affichage ---------- */

  var plural = function (n, word) { return n + ' ' + word + (n > 1 ? 's' : ''); };

  var setUnread = function (n) {
    // Titre de l'onglet
    document.title = n > 0 ? '(' + n + ') ' + baseTitle : baseTitle;

    // Pastille du menu (créée au premier message non lu)
    var link = document.querySelector('.sidebar__link[href="messages.php"]');
    if (link) {
      var count = link.querySelector('[data-unread-count]');
      if (n > 0) {
        if (!count) {
          count = document.createElement('span');
          count.className = 'sidebar__count';
          count.setAttribute('data-unread-count', '');
          link.appendChild(count);
        }
        count.textContent = n;
        count.title = plural(n, 'message') + ' non ' + (n > 1 ? 'lus' : 'lu');
      } else if (count) {
        count.remove();
      }
    }

    // Pastille du panneau « Messages du site » (accueil)
    document.querySelectorAll('[data-unread-badge]').forEach(function (badge) {
      badge.textContent = n + ' non ' + (n > 1 ? 'lus' : 'lu');
      badge.hidden = n === 0;
    });

    // Bouton « Tout marquer comme lu » (page Messages)
    var readAll = document.querySelector('[data-read-all]');
    if (readAll) readAll.hidden = n === 0;
  };

  var setCounts = function (counts) {
    Object.keys(counts || {}).forEach(function (key) {
      var el = document.querySelector('[data-filter-count="' + key + '"]');
      if (el) el.textContent = counts[key];
    });
  };

  // Lignes de conversation renvoyées par le serveur (même rendu que la page, jetons CSRF compris), placées en tête
  // de liste ; la ligne existante du même expéditeur est remplacée (une seule ligne par conversation)
  var addRows = function (rows) {
    if (!list || !rows || !rows.length) return 0;
    var added = 0;
    // Les plus anciennes d'abord, chacune insérée en tête : l'ordre final reste « de la plus récente à la plus ancienne »
    rows.slice().reverse().forEach(function (row) {
      if (onUnreadFilter && row.status !== 'unread') return;
      var existing = list.querySelector('[data-thread="' + row.thread + '"]');
      if (existing) {
        if (existing.getAttribute('data-message-id') === String(row.id)) return;   // déjà à jour
        existing.remove();
      }
      var tpl = document.createElement('template');
      tpl.innerHTML = row.html.trim();
      var tr = tpl.content.firstElementChild;
      if (!tr) return;
      tr.classList.add('is-new');
      list.insertBefore(tr, list.firstElementChild);
      added++;
    });
    if (added) {
      var empty = live.querySelector('[data-messages-empty]');
      var table = live.querySelector('[data-messages-table]');
      if (empty) empty.hidden = true;
      if (table) table.hidden = false;
    }
    return added;
  };

  // Aperçu de l'accueil : remplacé seulement s'il a changé ; les messages qui n'y figuraient pas sont mis en évidence
  var setPanel = function (html) {
    if (!panel || typeof html !== 'string' || panel.innerHTML.trim() === html.trim()) return;
    var known = {};
    panel.querySelectorAll('[data-message-id]').forEach(function (li) { known[li.getAttribute('data-message-id')] = true; });
    panel.innerHTML = html;
    panel.querySelectorAll('[data-message-id]').forEach(function (li) {
      if (!known[li.getAttribute('data-message-id')]) li.classList.add('is-new');
    });
  };

  // Conversation ouverte : nouveaux messages ajoutés en fin de fil (sans doublon)
  var addThreadItems = function (items) {
    if (!thread || !items || !items.length) return 0;
    var added = 0;
    items.forEach(function (item) {
      if (thread.querySelector('[data-message-id="' + item.id + '"]')) return;
      var li = toElement(item.html);
      if (!li) return;
      li.classList.add('is-new');
      thread.appendChild(li);
      added++;
    });
    return added;
  };

  /* ---------- Notification discrète ---------- */

  var toastBox = null;
  var notify = function (fresh) {
    if (!fresh.length) return;
    if (!toastBox) {
      toastBox = document.createElement('div');
      toastBox.className = 'live-toasts';
      toastBox.setAttribute('role', 'status');
      toastBox.setAttribute('aria-live', 'polite');
      body.appendChild(toastBox);
    }
    var toast = document.createElement('a');
    toast.className = 'live-toast';
    toast.href = fresh.length === 1 ? 'messages.php?id=' + encodeURIComponent(fresh[0].id) : 'messages.php';
    var title = document.createElement('strong');
    title.textContent = fresh.length === 1 ? 'Nouveau message de ' + fresh[0].sender : fresh.length + ' nouveaux messages';
    var text = document.createElement('span');
    text.textContent = fresh.length === 1 ? fresh[0].subject : 'Ouvrir la messagerie';
    toast.appendChild(title);
    toast.appendChild(text);
    toastBox.appendChild(toast);
    setTimeout(function () { toast.classList.add('is-leaving'); }, 7000);
    setTimeout(function () { toast.remove(); }, 7600);
  };

  /* ---------- Interrogation du serveur ---------- */

  var schedule = function () {
    clearTimeout(timer);
    if (!stopped && document.visibilityState === 'visible') timer = setTimeout(poll, delay);
  };

  var poll = function () {
    if (busy || stopped) return;
    busy = true;
    var url = api + '?since=' + lastId
      + (list ? '&rows=1&back=' + encodeURIComponent(live.getAttribute('data-back') || 'messages.php') : '')
      + (panel ? '&panel=1' : '')
      + (thread ? '&thread=' + encodeURIComponent(thread.getAttribute('data-thread-live')) : '');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) {
        if (r.status === 401 || r.status === 403) { stopped = true; return null; }   // session expirée / droits retirés
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        if (!data) return;
        delay = INTERVAL;
        var fresh = (data.new || []).filter(function (m) { return m.id > lastId; });
        lastId = Math.max(lastId, data.latest_id || 0);
        setUnread(data.unread || 0);
        setCounts(data.counts);
        setPanel(data.panel);
        var added = addRows(data.rows) + addThreadItems(data.thread_items);
        // Sur la liste ou dans la conversation, les nouveaux messages apparaissent directement ; ailleurs, une notification les signale
        if (!added) notify(fresh.filter(function (m) { return m.status === 'unread'; }));
      })
      .catch(function () { delay = Math.min(delay * 2, MAX_INTERVAL); })
      .then(function () { busy = false; schedule(); });
  };

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') { delay = INTERVAL; poll(); }
    else clearTimeout(timer);
  });

  schedule();
})();
