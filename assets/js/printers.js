/**
 * Global Trading Corporation — Imprimantes : actualisation en temps réel et envoi direct
 *
 * 1. Sélecteur [data-printer-picker] (factures, page d'impression) :
 *    bouton « Actualiser / Détecter les imprimantes » → dashboard/printers-api.php?action=list&refresh=1
 *    (état de chaque imprimante + découverte du réseau local), puis actualisation silencieuse
 *    toutes les minutes tant que la page est affichée.
 * 2. Page d'impression (body[data-direct="1"]) : la facture double souche est envoyée directement
 *    à l'imprimante choisie ; en cas d'échec, la boîte d'impression du navigateur reste proposée.
 */
(function () {
  'use strict';

  var STATUS_TEXT = { online: 'prête', offline: 'hors ligne' };

  /* ---------- 1. Sélecteur d'imprimante ---------- */
  document.querySelectorAll('[data-printer-picker]').forEach(function (box) {
    var api     = box.getAttribute('data-api');
    var select  = box.querySelector('select');
    var button  = box.querySelector('[data-printer-refresh]');
    var status  = box.querySelector('[data-printer-status]');
    var compact = box.hasAttribute('data-compact');
    var busy = false;
    if (!api || !select) return;

    var label = function (p) {
      var text = p.name;
      if (!compact) text += ' · ' + p.format_label + (p.is_default ? ' (par défaut)' : '');
      if (STATUS_TEXT[p.status]) text += ' — ' + STATUS_TEXT[p.status];
      if (p.direct) text += ' · envoi direct';
      return text;
    };

    var el = function (tag, text, attrs) {
      var node = document.createElement(tag);
      if (text) node.textContent = text;
      Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
      return node;
    };

    var render = function (data) {
      // Options : la sélection actuelle est conservée si l'imprimante est toujours disponible
      var current = select.value;
      var keep = data.printers.some(function (p) { return String(p.id) === current; });
      var fragment = document.createDocumentFragment();
      data.printers.forEach(function (p) {
        var option = el('option', label(p), { value: String(p.id), title: p.location });
        option.selected = keep ? String(p.id) === current : p.is_default;
        fragment.appendChild(option);
      });
      select.replaceChildren(fragment);
      if (!keep && current !== select.value) select.dispatchEvent(new Event('change', { bubbles: true }));

      if (!status) return;
      var count = function (s) { return data.printers.filter(function (p) { return p.status === s; }).length; };
      var parts = [data.printers.length + (data.printers.length > 1 ? ' imprimantes' : ' imprimante')];
      if (count('online')) parts.push(count('online') + (count('online') > 1 ? ' prêtes' : ' prête'));
      if (count('offline')) parts.push(count('offline') + ' hors ligne');
      if (count('nohost')) parts.push(count('nohost') + ' sans adresse IP');
      var line = parts.join(' · ') + (data.checked_at ? ' — actualisé à ' + data.checked_at : '');
      if (data.discovery.enabled && data.discovery.scanned_at) {
        line += ' · réseau ' + data.discovery.subnet + ' analysé à ' + data.discovery.scanned_at;
      }
      status.replaceChildren(document.createTextNode(line));
      status.classList.remove('is-error');

      if (data.discovery.count) {
        var found = el('span', null, { class: 'printer-picker__found' });
        found.appendChild(document.createTextNode(
          data.discovery.count + (data.discovery.count > 1 ? ' nouveaux appareils détectés' : ' nouvel appareil détecté') +
          (data.can_manage ? ' : ' : ' (un administrateur peut les ajouter)')));
        if (data.can_manage) {
          data.discovery.devices.forEach(function (d, i) {
            if (i) found.appendChild(document.createTextNode(', '));
            found.appendChild(el('a', d.host + ' (' + d.protocol + ')', {
              href: 'settings.php?add_host=' + encodeURIComponent(d.host) + '&add_port=' + d.port + '#systeme'
            }));
          });
        }
        status.appendChild(found);
      } else if (data.printers.length && count('nohost') === data.printers.length) {
        status.appendChild(document.createTextNode(' '));
        status.appendChild(el('span', "Renseignez l'adresse IP des imprimantes dans les Paramètres pour suivre leur état en temps réel.", { class: 'printer-picker__hint' }));
      }
    };

    var refresh = function (options) {
      if (busy) return;
      busy = true;
      var manual = options && options.manual;
      if (manual && button) {
        button.disabled = true;
        button.classList.add('is-loading');
        if (status) status.textContent = 'Détection des imprimantes en cours…';
      }
      var url = api + '?action=list&refresh=1' + (options && options.scan === false ? '&scan=0' : '') + (manual ? '&force=1' : '');
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) {
          return r.json().then(function (data) {
            if (!r.ok) throw new Error(data.error || 'Erreur ' + r.status);
            return data;
          });
        })
        .then(render)
        .catch(function (err) {
          if (!status) return;
          status.textContent = 'Actualisation impossible : ' + (err && err.message && err.message.indexOf('JSON') < 0 ? err.message : 'session expirée ou serveur injoignable.');
          status.classList.add('is-error');
        })
        .then(function () {
          busy = false;
          if (button) { button.disabled = false; button.classList.remove('is-loading'); }
        });
    };

    if (button) button.addEventListener('click', function () { refresh({ manual: true }); });
    refresh({ manual: false });
    // Actualisation silencieuse de l'état (sans balayage du réseau) toutes les minutes
    setInterval(function () {
      if (document.visibilityState === 'visible') refresh({ scan: false });
    }, 60000);
  });

  /* ---------- 2. Envoi direct depuis la page d'impression ---------- */
  var body = document.body;
  var statusBox = document.querySelector('[data-direct-status]');

  var showDirect = function (kind, message, fallback) {
    if (!statusBox) return;
    statusBox.hidden = false;
    statusBox.className = 'print-direct print-direct--' + kind;
    statusBox.replaceChildren(document.createTextNode(message));
    if (fallback) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pt-btn';
      btn.textContent = "Imprimer via le navigateur";
      btn.addEventListener('click', function () { window.print(); });
      statusBox.appendChild(btn);
    }
  };

  var sendDirect = function () {
    var params = new URLSearchParams({
      action: 'send',
      id: body.getAttribute('data-invoice'),
      printer_id: body.getAttribute('data-printer-id'),
      csrf_token: body.getAttribute('data-csrf')
    });
    showDirect('pending', 'Envoi de la facture à « ' + body.getAttribute('data-printer') + ' »…');
    fetch(body.getAttribute('data-api'), {
      method: 'POST',
      body: params,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) showDirect('success', data.message);
        else showDirect('error', (data.error || "L'envoi a échoué.") + ' ', true);
      })
      .catch(function () {
        showDirect('error', "Le serveur ne répond pas : l'envoi direct n'a pas pu être vérifié. ", true);
      });
  };

  window.GTCPrinters = {
    /** Impression : envoi direct si l'imprimante le permet, sinon boîte d'impression du navigateur. */
    print: function () {
      if (body.getAttribute('data-direct') === '1') sendDirect(); else window.print();
    }
  };
})();
