/**
 * Global Trading Corporation — Simulateur avancé du guichet (dashboard/simulator.php)
 *
 * frais           = montant envoyé × % ou montant fixe (devise source)
 * net à convertir = montant envoyé − frais
 * montant reçu    = net × taux (sens « multiply ») ou net ÷ taux (sens « divide »)
 * Calcul inverse  : montant reçu → net → montant envoyé (frais réintégrés).
 */
(function () {
  'use strict';

  var root = document.getElementById('simulator');
  if (!root) return;

  // Grille de l'entreprise : { EUR: { buy, sell }, … } en FCFA pour 1 unité (XAF : 1 / 1)
  var RATES    = JSON.parse(root.getAttribute('data-rates') || '{}');
  var DECIMALS = JSON.parse(root.getAttribute('data-decimals') || '{}');
  var SOURCE   = root.getAttribute('data-source') || 'la grille GTC';
  // 1 source = x cible : le guichet achète la source et vend la cible
  var unitRate = function (s, t) {
    if (s === t) return 1;
    return RATES[s] && RATES[t] ? RATES[s].buy / RATES[t].sell : NaN;
  };
  var MAJORS   = ['EUR', 'USD', 'XAF', 'CAD', 'AED'];                      // ordre de préférence du comparatif

  // Devises proposées par défaut selon l'opération
  var DEFAULTS = {
    'Exchange:buy':  ['EUR', 'XAF'],   // le client vend ses devises → reçoit des FCFA
    'Exchange:sell': ['XAF', 'EUR'],   // le client achète des devises
    'Transfert':     ['EUR', 'XAF'],
    'Import/Export': ['XAF', 'USD']
  };

  var $ = function (id) { return document.getElementById(id); };
  var fSource = $('sim-source'), fTarget = $('sim-target'), fRate = $('sim-rate'), fDir = $('sim-direction');
  var fFee = $('sim-fee'), fSend = $('sim-send'), fReceive = $('sim-receive');
  var cta = $('sim-to-invoice');

  var state = { operation: 'Exchange', mode: 'buy', feeType: 'percent', lastEdited: 'send', sendAmount: NaN, fee: 0 };

  /* ---------- Outils ---------- */
  var label = function (code) { return code === 'XAF' ? 'FCFA' : code; };
  var dec = function (code) { return DECIMALS[code] != null ? DECIMALS[code] : 2; };
  var num = function (v) {
    var n = parseFloat(String(v).replace(/[\s  ]/g, '').replace(',', '.'));
    return isFinite(n) ? n : NaN;
  };
  var round = function (v, d) { var f = Math.pow(10, d); return Math.round(v * f) / f; };
  var ceil = function (v, d) { var f = Math.pow(10, d); return Math.ceil(v * f - 1e-9) / f; };
  var fmt = function (v, code) {
    return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: dec(code), maximumFractionDigits: dec(code) }).format(v) +
      ' ' + label(code);
  };
  var fmtRate = function (r) { return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 4 }).format(r); };
  var inputValue = function (v, code) { return String(round(v, dec(code))).replace('.', ','); };

  var convert = function (amount, rate) { return fDir.value === 'divide' ? amount / rate : amount * rate; };
  var invert  = function (amount, rate) { return fDir.value === 'divide' ? amount * rate : amount / rate; };

  /* ---------- Taux ---------- */
  var rateText = function () {
    var r = num(fRate.value);
    if (!(r > 0)) return '—';
    return fDir.value === 'divide'
      ? '1 ' + label(fTarget.value) + ' = ' + fmtRate(r) + ' ' + label(fSource.value)
      : '1 ' + label(fSource.value) + ' = ' + fmtRate(r) + ' ' + label(fTarget.value);
  };

  // Taux indicatif présenté dans le sens le plus lisible (taux ≥ 1)
  var suggestRate = function () {
    var s = fSource.value, t = fTarget.value, r;
    if (s === t) { r = 1; fDir.value = 'multiply'; }
    else {
      r = unitRate(s, t);
      if (!isFinite(r) || r <= 0) return;
      if (r >= 1) { fDir.value = 'multiply'; } else { fDir.value = 'divide'; r = 1 / r; }
    }
    fRate.value = String(round(r, 4)).replace('.', ',');
  };

  /* ---------- Calcul ---------- */
  var feeFor = function (send) {
    var v = num(fFee.value);
    if (!(v > 0)) return 0;
    var fee = state.feeType === 'percent' ? send * v / 100 : v;
    return Math.min(round(fee, dec(fSource.value)), send);
  };

  var compute = function () {
    var s = fSource.value, t = fTarget.value, rate = num(fRate.value);
    var feeVal = num(fFee.value);
    var send = NaN, receive = NaN, fee = 0, net = NaN;
    var validFee = fFee.value === '' || feeVal >= 0 && !(state.feeType === 'percent' && feeVal >= 100);

    if (rate > 0 && validFee) {
      if (state.lastEdited === 'send') {
        send = num(fSend.value);
        if (send > 0) {
          fee = feeFor(send);
          net = send - fee;
          receive = round(convert(net, rate), dec(t));
          fReceive.value = receive > 0 ? inputValue(receive, t) : '';
        } else { fReceive.value = ''; }
      } else {
        receive = num(fReceive.value);
        if (receive > 0) {
          net = invert(receive, rate);
          var p = feeVal > 0 ? feeVal : 0;
          // Montant à verser arrondi au-dessus : le client reçoit au moins le montant souhaité
          send = state.feeType === 'percent' ? ceil(net / (1 - p / 100), dec(s)) : ceil(net + p, dec(s));
          fSend.value = inputValue(send, s);
          // Montants réellement appliqués à partir du montant à verser arrondi : identiques à la facture
          fee = feeFor(send);
          net = send - fee;
          receive = round(convert(net, rate), dec(t));
        } else { fSend.value = ''; }
      }
    }

    var ok = send > 0 && receive > 0;
    state.sendAmount = ok ? send : NaN;
    state.fee = ok ? fee : 0;
    render(ok, send, fee, net, receive);
  };

  /* ---------- Affichage ---------- */
  var render = function (ok, send, fee, net, receive) {
    var s = fSource.value, t = fTarget.value, feeVal = num(fFee.value);

    document.querySelectorAll('.sim-cur').forEach(function (el) {
      el.textContent = label(el.getAttribute('data-cur') === 'source' ? s : t);
    });
    $('sim-fee-fixed-label').textContent = 'Montant (' + label(s) + ')';
    $('sim-fee-hint').textContent = state.feeType === 'percent'
      ? 'Pourcentage du montant envoyé, prélevé avant conversion.'
      : 'Montant fixe en ' + label(s) + ', prélevé avant conversion.';
    $('sim-rate-hint').textContent = 'Taux appliqué : ' + rateText() + ' · taux de ' + SOURCE;
    fDir.options[0].text = '1 ' + label(s) + ' = taux ' + label(t) + ' (×)';
    fDir.options[1].text = '1 ' + label(t) + ' = taux ' + label(s) + ' (÷)';

    $('r-subtotal').textContent   = ok ? fmt(send, s) : '—';
    $('r-fee').textContent        = ok ? (fee > 0 ? '− ' + fmt(fee, s) : fmt(0, s)) : '—';
    $('r-fee-rate').textContent   = state.feeType === 'percent' && feeVal > 0 ? '(' + fmtRate(feeVal) + ' %)' : '';
    $('r-net-source').textContent = ok ? fmt(net, s) : '—';
    $('r-rate').textContent       = rateText();
    $('r-final').textContent      = ok ? fmt(receive, t) : '—';

    // Passerelle vers la facturation (champs pré-remplis)
    if (ok) {
      var params = new URLSearchParams({
        operation_type: state.operation,
        source_currency: s,
        target_currency: t,
        amount_given: String(round(send, dec(s))),
        exchange_rate: String(round(num(fRate.value), 4)),
        rate_direction: fDir.value,
        fees: String(round(fee, dec(s)))
      });
      cta.href = 'invoices.php?' + params.toString();
      cta.removeAttribute('aria-disabled');
    } else {
      cta.href = 'invoices.php';
      cta.setAttribute('aria-disabled', 'true');
    }

    renderCompare(ok ? send : NaN, s);
  };

  // Équivalent du montant envoyé dans 3 devises majeures (taux indicatifs du site)
  var renderCompare = function (amount, source) {
    var box = $('sim-compare');
    var targets = MAJORS.filter(function (c) { return c !== source && RATES[c]; }).slice(0, 3);
    $('cmp-note').textContent = 'Taux de ' + SOURCE;

    if (!(amount > 0)) {
      box.innerHTML = '<p class="empty">Saisissez un montant pour afficher ses équivalents.</p>';
      return;
    }
    var html = '<div class="sim-compare__grid">';
    targets.forEach(function (c) {
      var r = unitRate(source, c);
      var unit = r >= 1 ? '1 ' + label(source) + ' ≈ ' + fmtRate(r) + ' ' + label(c)
                        : '1 ' + label(c) + ' ≈ ' + fmtRate(1 / r) + ' ' + label(source);
      html += '<div class="sim-compare__item"><span class="sim-compare__code">' + label(c) + '</span>' +
              '<strong>' + fmt(amount * r, c) + '</strong><small>' + unit + '</small></div>';
    });
    box.innerHTML = html + '</div>';
  };

  /* ---------- Opérations & devises par défaut ---------- */
  var applyDefaults = function () {
    var key = state.operation === 'Exchange' ? 'Exchange:' + state.mode : state.operation;
    var pair = DEFAULTS[key];
    fSource.value = pair[0];
    fTarget.value = pair[1];
    suggestRate();
    compute();
  };

  document.querySelectorAll('.sim-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.sim-tab').forEach(function (t) {
        var active = t === tab;
        t.classList.toggle('is-active', active);
        t.setAttribute('aria-selected', String(active));
      });
      state.operation = tab.getAttribute('data-operation');
      $('sim-mode').hidden = state.operation !== 'Exchange';
      applyDefaults();
    });
  });

  document.querySelectorAll('.sim-mode__btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.sim-mode__btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      state.mode = btn.getAttribute('data-mode');
      applyDefaults();
    });
  });

  document.querySelectorAll('.seg__btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.seg__btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      state.feeType = btn.getAttribute('data-fee-type');
      compute();
    });
  });

  /* ---------- Saisie ---------- */
  fSend.addEventListener('input', function () { state.lastEdited = 'send'; compute(); });
  fReceive.addEventListener('input', function () { state.lastEdited = 'receive'; compute(); });
  [fRate, fFee].forEach(function (el) { el.addEventListener('input', compute); });
  fDir.addEventListener('change', compute);
  [fSource, fTarget].forEach(function (el) {
    el.addEventListener('change', function () { suggestRate(); compute(); });
  });
  $('sim-rate-suggest').addEventListener('click', function () { suggestRate(); compute(); });

  $('sim-swap').addEventListener('click', function () {
    var tmp = fSource.value;
    fSource.value = fTarget.value;
    fTarget.value = tmp;
    suggestRate();
    compute();
  });

  cta.addEventListener('click', function (e) {
    if (cta.getAttribute('aria-disabled') === 'true') {
      e.preventDefault();
      fSend.focus();
    }
  });

  applyDefaults();
})();
