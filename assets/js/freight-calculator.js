/**
 * Global Trading Corporation — Calculateur de volume (CBM) et d'estimation de fret (calculateur-cbm.php)
 *
 * Lignes de colis (désignation, L × l × H, quantité, poids par colis facultatif), unité cm ou m, mode maritime ou
 * aérien ; tout est recalculé à chaque saisie.
 *   volume (m³)          = L × l × H (cm) / 1 000 000 × quantité
 *   poids volumétrique   = L × l × H (cm) / 6 000 × quantité
 *   maritime : base = max(volume, poids réel en t) et au moins le minimum de groupage ; coût = base × tarif / m³
 *   aérien   : base = max(poids réel, poids volumétrique), arrondie au demi-kilo supérieur ; coût = base × tarif / kg
 * Tarifs (FCFA) lus dans les attributs data-* de la page (Dashboard → Paramètres → Taux) ; vides = pas d'estimation.
 * Le bouton « Demander un devis » envoie le détail du calcul sur WhatsApp.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-freight]');
  if (!root) return;
  root.hidden = false;

  var AIR_DIVISOR = 6000;
  var MAX_LINES = 20;
  var MAX_DIM_CM = 2000;          // 20 m : au-delà, saisie probablement erronée
  var MAX_QTY = 100000;

  var num = function (v) { var n = parseFloat(String(v).replace(/\s/g, '').replace(',', '.')); return isFinite(n) ? n : 0; };
  var rates = {
    sea: num(root.getAttribute('data-rate-sea')),
    air: num(root.getAttribute('data-rate-air')),
    seaMin: num(root.getAttribute('data-sea-min'))
  };

  var linesBox = root.querySelector('[data-lines]');
  var addBtn = root.querySelector('[data-add]');
  var out = function (name) { return root.querySelector('[data-out="' + name + '"]'); };
  var fmt = function (n, decimals) {
    return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: decimals, minimumFractionDigits: 0 }).format(n);
  };
  var money = function (n) { return fmt(Math.round(n), 0) + ' FCFA'; };
  var mode = function () { return root.querySelector('[name="freight-mode"]:checked').value; };
  var unit = function () { return root.querySelector('[name="freight-unit"]:checked').value; };

  /* ---------- Lignes de colis ---------- */

  var counter = 0;
  var letter = function (i) { return String.fromCharCode(65 + (i % 26)); };

  var field = function (cls, label, name, attrs) {
    var id = 'fl-' + counter + '-' + name;
    return '<div class="freight-line__field ' + cls + '"><label for="' + id + '">' + label + '</label>' +
      '<input id="' + id + '" name="' + name + '" ' + attrs + '></div>';
  };

  var addLine = function (values) {
    if (linesBox.children.length >= MAX_LINES) return;
    counter++;
    var line = document.createElement('div');
    line.className = 'freight-line';
    line.innerHTML =
      '<div class="freight-line__head">' +
        '<input class="freight-line__name" name="name" maxlength="40" aria-label="Désignation du type de colis">' +
        '<span class="freight-line__cbm" data-line-cbm>0 m³</span>' +
        '<button type="button" class="freight-line__remove" data-remove aria-label="Supprimer ce type de colis" title="Supprimer">×</button>' +
      '</div>' +
      '<div class="freight-line__grid">' +
        field('', 'Longueur <span data-unit>cm</span>', 'l', 'inputmode="decimal" autocomplete="off" placeholder="0"') +
        field('', 'Largeur <span data-unit>cm</span>', 'w', 'inputmode="decimal" autocomplete="off" placeholder="0"') +
        field('', 'Hauteur <span data-unit>cm</span>', 'h', 'inputmode="decimal" autocomplete="off" placeholder="0"') +
        field('', 'Quantité', 'q', 'inputmode="numeric" autocomplete="off" value="1"') +
        field('freight-line__weight', 'Poids / colis <small>kg, facultatif</small>', 'kg', 'inputmode="decimal" autocomplete="off" placeholder="—"') +
      '</div>';
    line.querySelector('[name="name"]').value = (values && values.name) || 'Type ' + letter(linesBox.children.length);
    ['l', 'w', 'h', 'q', 'kg'].forEach(function (k) {
      if (values && values[k] !== undefined) line.querySelector('[name="' + k + '"]').value = values[k];
    });
    linesBox.appendChild(line);
    refreshUnits();
    update();
  };

  var refreshUnits = function () {
    var u = unit();
    root.querySelectorAll('[data-unit]').forEach(function (el) { el.textContent = u; });
    root.querySelectorAll('.freight-line [name="l"], .freight-line [name="w"], .freight-line [name="h"]').forEach(function (input) {
      input.placeholder = u === 'cm' ? '0' : '0,00';
    });
    var only = linesBox.children.length === 1;
    root.querySelectorAll('[data-remove]').forEach(function (b) { b.hidden = only; });
    addBtn.hidden = linesBox.children.length >= MAX_LINES;
  };

  /* ---------- Calcul ---------- */

  var readLines = function () {
    var factor = unit() === 'm' ? 100 : 1;   // dimensions ramenées en cm
    return Array.prototype.map.call(linesBox.children, function (line) {
      var get = function (k) { return line.querySelector('[name="' + k + '"]'); };
      var dims = ['l', 'w', 'h'].map(function (k) { return num(get(k).value) * factor; });
      var qty = Math.floor(num(get('q').value));
      var kgRaw = get('kg').value.replace(/\s/g, '');
      var kgBad = kgRaw !== '' && !/^\d+([.,]\d+)?$/.test(kgRaw);   // poids illisible : signalé, ignoré dans le calcul
      var kg = kgBad ? 0 : num(kgRaw);
      var valid = dims.every(function (d) { return d > 0 && d <= MAX_DIM_CM; }) && qty > 0 && qty <= MAX_QTY;
      // Champ hors limites signalé (valeur saisie mais impossible)
      dims.forEach(function (d, i) {
        var input = get(['l', 'w', 'h'][i]);
        input.setAttribute('aria-invalid', input.value.trim() !== '' && !(d > 0 && d <= MAX_DIM_CM) ? 'true' : 'false');
      });
      get('q').setAttribute('aria-invalid', get('q').value.trim() !== '' && !(qty > 0 && qty <= MAX_QTY) ? 'true' : 'false');
      get('kg').setAttribute('aria-invalid', kgBad ? 'true' : 'false');
      var cm3 = valid ? dims[0] * dims[1] * dims[2] : 0;
      return {
        el: line,
        name: get('name').value.trim() || 'Colis',
        dims: dims, factor: factor, raw: ['l', 'w', 'h'].map(function (k) { return get(k).value.trim(); }),
        qty: valid ? qty : 0,
        kg: valid ? kg : 0,
        hasKg: valid && kgRaw !== '' && !kgBad,
        cbm: valid ? cm3 / 1e6 * qty : 0,
        volumetric: valid ? cm3 / AIR_DIVISOR * qty : 0,
        valid: valid
      };
    });
  };

  var last = null;

  var update = function () {
    var lines = readLines();
    var t = { count: 0, cbm: 0, volumetric: 0, weight: 0, weightKnown: true, valid: 0 };
    lines.forEach(function (l) {
      l.el.querySelector('[data-line-cbm]').textContent = l.valid ? fmt(l.cbm, 3) + ' m³' : '— m³';
      if (!l.valid) return;
      t.valid++;
      t.count += l.qty;
      t.cbm += l.cbm;
      t.volumetric += l.volumetric;
      t.weight += l.kg * l.qty;
      if (!l.hasKg) t.weightKnown = false;
    });

    out('count').textContent = fmt(t.count, 0);
    out('cbm').textContent = fmt(t.cbm, 3);
    out('volumetric').textContent = fmt(t.volumetric, 1);
    out('weight').textContent = t.valid && t.weight > 0 ? fmt(t.weight, 1) + ' kg' + (t.weightKnown ? '' : ' (partiel)') : '—';

    var m = mode();
    var basis, basisText, why, rate, cost;
    if (m === 'sea') {
      var tonnes = t.weight / 1000;
      basis = Math.max(t.cbm, tonnes);
      why = tonnes > t.cbm ? 'Poids supérieur au volume : facturé au poids (1 t = 1 m³).' : 'Facturé au volume.';
      if (t.valid && rates.seaMin > 0 && basis < rates.seaMin) {
        basis = rates.seaMin;
        why = 'Minimum facturé en groupage : ' + fmt(rates.seaMin, 2) + ' m³.';
      }
      basisText = fmt(basis, 3) + ' m³';
      rate = rates.sea;
      cost = basis * rate;
      out('basis-label').textContent = 'Volume taxable';
      out('rate').textContent = rate ? 'Base ' + fmt(rate, 0) + ' FCFA / m³' : '';
    } else {
      basis = Math.ceil(Math.max(t.weight, t.volumetric) * 2) / 2;
      why = t.weight > t.volumetric ? 'Poids réel supérieur au poids volumétrique.' : 'Poids volumétrique (L × l × H / 6000) supérieur au poids réel.';
      basisText = fmt(basis, 1) + ' kg';
      rate = rates.air;
      cost = basis * rate;
      out('basis-label').textContent = 'Poids taxable';
      out('rate').textContent = rate ? 'Base ' + fmt(rate, 0) + ' FCFA / kg' : '';
    }
    if (!t.valid) { basisText = m === 'sea' ? '0 m³' : '0 kg'; why = 'Saisissez les dimensions d\'au moins un colis.'; cost = 0; }

    out('basis').textContent = basisText;
    out('basis-why').textContent = why;
    root.querySelector('[data-estimate-block]').hidden = !rate;
    root.querySelector('[data-no-rate]').hidden = !!rate;
    out('cost').textContent = t.valid && rate ? money(cost) : '—';

    last = { lines: lines, totals: t, mode: m, basisText: basisText, cost: t.valid && rate ? cost : 0 };
    updateQuoteLink();
  };

  /* ---------- Demande de devis (WhatsApp) ---------- */

  var updateQuoteLink = function () {
    var link = root.querySelector('[data-quote]');
    if (!last) return;
    var u = unit();
    var rows = last.lines.filter(function (l) { return l.valid; }).map(function (l) {
      return '- ' + l.name + ' : ' + l.qty + ' × ' + l.raw.join(' × ') + ' ' + u + (l.hasKg ? ', ' + fmt(l.kg, 1) + ' kg/colis' : '') + ' (' + fmt(l.cbm, 3) + ' m³)';
    });
    var text = 'Bonjour GTC, je souhaite un devis de fret ' + (last.mode === 'sea' ? 'maritime' : 'aérien') + ' vers Douala.\n' +
      (rows.length
        ? rows.join('\n') + '\nTotal : ' + fmt(last.totals.count, 0) + ' colis, ' + fmt(last.totals.cbm, 3) + ' m³' +
          (last.totals.weight > 0 ? ', ' + fmt(last.totals.weight, 1) + ' kg' : '') +
          (last.mode === 'air' ? ', poids volumétrique ' + fmt(last.totals.volumetric, 1) + ' kg' : '') +
          (last.cost ? '\nEstimation du calculateur : ' + money(last.cost) : '')
        : '');
    link.href = 'https://wa.me/' + root.getAttribute('data-whatsapp') + '?text=' + encodeURIComponent(text);
  };

  /* ---------- Événements ---------- */

  root.addEventListener('input', update);
  root.addEventListener('change', function (e) {
    if (e.target.name === 'freight-unit') refreshUnits();
    update();
  });
  root.addEventListener('click', function (e) {
    var remove = e.target.closest('[data-remove]');
    if (remove && linesBox.children.length > 1) {
      var line = remove.closest('.freight-line');
      var next = line.nextElementSibling || line.previousElementSibling;
      line.remove();
      refreshUnits();
      update();
      if (next) next.querySelector('[name="l"]').focus();
    }
  });
  addBtn.addEventListener('click', function () {
    addLine();
    linesBox.lastElementChild.querySelector('[name="l"]').focus();
  });

  addLine();
})();
