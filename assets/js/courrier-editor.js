/**
 * Courriers (dashboard/courriers.php) : éditeur de texte enrichi « façon Word » du corps de la lettre,
 * boutons de variables de publipostage et sélecteur de modèles pré-remplis.
 *
 * Sans dépendance externe (fonctionne hors ligne) : zone contenteditable + document.execCommand.
 * Le HTML produit est recopié dans le <textarea name="body"> d'origine, que le serveur filtre strictement
 * (courrier_sanitize_html, includes/courriers.php). Sans JavaScript, le textarea reste utilisable tel quel.
 */
(function () {
  'use strict';

  var mount = document.querySelector('[data-rte-for]');
  var textarea = mount && document.getElementById(mount.getAttribute('data-rte-for'));
  if (!mount || !textarea || typeof document.execCommand !== 'function') return;

  /* ==========================================================================
     Construction de l'éditeur
     ========================================================================== */

  var ICONS = {
    bold: '<b>G</b>', italic: '<i>I</i>', underline: '<u>S</u>',
    ul: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="7" r="1.6"/><circle cx="5" cy="12" r="1.6"/><circle cx="5" cy="17" r="1.6"/><path d="M9.5 7h10M9.5 12h10M9.5 17h10"/></svg>',
    ol: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 7h10M10 12h10M10 17h10"/><text x="3" y="9" font-size="6.5">1</text><text x="3" y="14" font-size="6.5">2</text><text x="3" y="19" font-size="6.5">3</text></svg>',
    left: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 10h10M4 14h16M4 18h10"/></svg>',
    center: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 10h10M4 14h16M7 18h10"/></svg>',
    right: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M10 10h10M4 14h16M10 18h10"/></svg>',
    justify: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>',
    undo: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 7L4 12l5 5M4 12h10a6 6 0 0 1 0 12"/></svg>',
    redo: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 7l5 5-5 5M20 12H10a6 6 0 0 0 0 12"/></svg>',
    clear: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 5h12M12 5l-3 14M4 20l16-16"/></svg>'
  };

  // [commande execCommand, icône, libellé (infobulle + accessibilité), commande d'état ou null]
  var GROUPS = [
    [['bold', 'bold', 'Gras (Ctrl+B)', 'bold'], ['italic', 'italic', 'Italique (Ctrl+I)', 'italic'], ['underline', 'underline', 'Souligné (Ctrl+U)', 'underline']],
    [['insertUnorderedList', 'ul', 'Liste à puces', 'insertUnorderedList'], ['insertOrderedList', 'ol', 'Liste numérotée', 'insertOrderedList']],
    [['justifyLeft', 'left', 'Aligner à gauche', 'justifyLeft'], ['justifyCenter', 'center', 'Centrer', 'justifyCenter'],
     ['justifyRight', 'right', 'Aligner à droite', 'justifyRight'], ['justifyFull', 'justify', 'Justifier (par défaut)', 'justifyFull']],
    [['undo', 'undo', 'Annuler (Ctrl+Z)', null], ['redo', 'redo', 'Rétablir (Ctrl+Y)', null], ['removeFormat', 'clear', 'Effacer la mise en forme', null]]
  ];

  var toolbar = document.createElement('div');
  toolbar.className = 'rte__toolbar';
  toolbar.setAttribute('role', 'toolbar');
  toolbar.setAttribute('aria-label', 'Mise en forme du corps du courrier');
  var stateButtons = [];
  GROUPS.forEach(function (group) {
    var g = document.createElement('div');
    g.className = 'rte__group';
    group.forEach(function (def) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'rte__btn';
      b.innerHTML = ICONS[def[1]];
      b.title = def[2];
      b.setAttribute('aria-label', def[2]);
      b.setAttribute('data-cmd', def[0]);
      if (def[3]) {
        b.setAttribute('aria-pressed', 'false');
        stateButtons.push([b, def[3]]);
      }
      g.appendChild(b);
    });
    toolbar.appendChild(g);
  });

  // Variables de publipostage : { "[Nom de l'entreprise]": "Entreprise destinataire", … }
  var variables = {};
  try { variables = JSON.parse(mount.getAttribute('data-variables') || '{}'); } catch (e) { /* aucune */ }
  var vars = document.createElement('div');
  vars.className = 'rte__vars';
  var varsLabel = document.createElement('span');
  varsLabel.className = 'rte__vars-label';
  varsLabel.textContent = 'Insérer :';
  vars.appendChild(varsLabel);
  Object.keys(variables).forEach(function (tag) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'rte__var';
    b.textContent = tag;
    b.title = 'Insère ' + tag + ' — remplacé par : ' + variables[tag];
    b.setAttribute('data-var', tag);
    vars.appendChild(b);
  });

  var editor = document.createElement('div');
  editor.className = 'rte__area';
  editor.contentEditable = 'true';
  editor.spellcheck = true;
  editor.lang = 'fr';
  editor.setAttribute('role', 'textbox');
  editor.setAttribute('aria-multiline', 'true');
  editor.setAttribute('aria-label', 'Corps du courrier');
  if (textarea.getAttribute('aria-invalid')) editor.setAttribute('aria-invalid', 'true');

  mount.appendChild(vars);
  mount.appendChild(toolbar);
  mount.appendChild(editor);
  mount.hidden = false;
  textarea.hidden = true;
  textarea.removeAttribute('required');   // le contrôle « corps vide » est fait par le serveur
  var label = document.querySelector('label[for="' + textarea.id + '"]');
  if (label) label.addEventListener('click', function (e) { e.preventDefault(); editor.focus(); });

  /* ==========================================================================
     Nettoyage du HTML (collage depuis Word / un site web, chargement d'un modèle)
     Même liste que le serveur : paragraphes, retours à la ligne, gras, italique, souligné, listes, alignement.
     ========================================================================== */

  var BLOCKS = { P: 1, DIV: 1, LI: 1, H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1, BLOCKQUOTE: 1 };
  var DROP = { SCRIPT: 1, STYLE: 1, META: 1, LINK: 1, TITLE: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, IMG: 1, SVG: 1, VIDEO: 1, AUDIO: 1, TEMPLATE: 1, NOSCRIPT: 1, INPUT: 1, BUTTON: 1, SELECT: 1, TEXTAREA: 1 };

  function alignOf(el) {
    var a = (el.style && el.style.textAlign) || el.getAttribute('align') || '';
    a = { start: 'left', end: 'right' }[a] || a;
    return /^(left|center|right|justify)$/.test(a) ? a : '';
  }

  function cleanNode(src, out) {
    Array.prototype.forEach.call(src.childNodes, function (n) {
      if (n.nodeType === 3) { out.appendChild(document.createTextNode(n.nodeValue)); return; }
      if (n.nodeType !== 1 || DROP[n.nodeName] || /:/.test(n.nodeName)) return;   // o:p, w:… (Word)
      var tag = n.nodeName, el = null, css = (n.getAttribute('style') || '').toLowerCase();
      if (tag === 'BR') { out.appendChild(document.createElement('br')); return; }
      if (tag === 'B' || tag === 'STRONG' || /font-weight\s*:\s*(bold|[6-9]00)/.test(css)) el = document.createElement('strong');
      else if (tag === 'I' || tag === 'EM' || /italic/.test(css)) el = document.createElement('em');
      else if (tag === 'U' || tag === 'INS' || /underline/.test(css)) el = document.createElement('u');
      else if (tag === 'UL' || tag === 'OL') el = document.createElement(tag.toLowerCase());
      else if (tag === 'LI') el = document.createElement('li');
      else if (BLOCKS[tag]) el = document.createElement('p');
      if (!el) { cleanNode(n, out); return; }   // span, font, a… : contenu seul
      if (el.nodeName === 'P' || el.nodeName === 'LI') {
        var a = alignOf(n);
        if (a) el.style.textAlign = a;
      }
      cleanNode(n, el);
      out.appendChild(el);
    });
    return out;
  }

  function cleanHtml(html) {
    var doc = new DOMParser().parseFromString('<body>' + html, 'text/html');
    var box = document.createElement('div');
    cleanNode(doc.body, box);
    // Paragraphes imbriqués (div > p) : aplatis
    Array.prototype.forEach.call(box.querySelectorAll('p p, li p'), function (p) {
      var frag = document.createDocumentFragment();
      while (p.firstChild) frag.appendChild(p.firstChild);
      if (p.nextSibling) frag.appendChild(document.createElement('br'));
      p.parentNode.replaceChild(frag, p);
    });
    return box.innerHTML;
  }

  /* ==========================================================================
     Contenu : chargement, synchronisation avec le textarea
     ========================================================================== */

  function isEmpty() {
    return editor.textContent.replace(/\s+/g, '') === '' && !editor.querySelector('li');
  }

  function ensureParagraph() {
    if (!editor.firstChild) editor.innerHTML = '<p><br></p>';
  }

  function sync() {
    textarea.value = isEmpty() ? '' : editor.innerHTML;
  }

  function setHtml(html) {
    editor.innerHTML = cleanHtml(html || '');
    ensureParagraph();
    sync();
  }

  try { document.execCommand('styleWithCSS', false, false); } catch (e) { /* ancien navigateur */ }
  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* idem */ }
  setHtml(textarea.value);

  editor.addEventListener('input', function () {
    // Texte tapé directement dans la zone (hors paragraphe) : enveloppé dans un <p>
    if (editor.firstChild && editor.firstChild.nodeType === 3) document.execCommand('formatBlock', false, 'p');
    ensureParagraph();
    sync();
  });
  textarea.form.addEventListener('submit', sync);

  editor.addEventListener('paste', function (e) {
    var data = e.clipboardData;
    if (!data) return;
    e.preventDefault();
    var html = data.getData('text/html');
    if (html) {
      document.execCommand('insertHTML', false, cleanHtml(html));
    } else {
      var text = data.getData('text/plain') || '';
      var paras = text.replace(/\r/g, '').split(/\n\s*\n/);
      if (paras.length < 2) {
        document.execCommand('insertText', false, text);
      } else {
        document.execCommand('insertHTML', false, paras.map(function (p) {
          var d = document.createElement('p');
          d.textContent = p.trim();
          return d.outerHTML.replace(/\n/g, '<br>');
        }).join(''));
      }
    }
  });

  // Glisser-déposer d'images ou de fichiers : refusé (le courrier n'accepte que du texte)
  editor.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) e.preventDefault();
  });

  /* ==========================================================================
     Barre d'outils, état des boutons, sélection mémorisée
     ========================================================================== */

  var savedRange = null;

  function inEditor(node) {
    return node && (node === editor || editor.contains(node));
  }

  function saveRange() {
    var sel = window.getSelection();
    if (sel.rangeCount && inEditor(sel.getRangeAt(0).commonAncestorContainer)) savedRange = sel.getRangeAt(0).cloneRange();
  }

  function restoreRange() {
    editor.focus();
    var sel = window.getSelection();
    if (savedRange && inEditor(savedRange.commonAncestorContainer)) {
      sel.removeAllRanges();
      sel.addRange(savedRange);
    } else if (!sel.rangeCount || !inEditor(sel.getRangeAt(0).commonAncestorContainer)) {
      var r = document.createRange();   // curseur en fin de texte
      r.selectNodeContents(editor);
      r.collapse(false);
      sel.removeAllRanges();
      sel.addRange(r);
    }
  }

  function refreshState() {
    var sel = window.getSelection();
    var inside = sel.rangeCount && inEditor(sel.getRangeAt(0).commonAncestorContainer);
    var anyAlign = false;
    stateButtons.forEach(function (sb) {
      var on = false;
      if (inside) { try { on = document.queryCommandState(sb[1]); } catch (e) { on = false; } }
      if (/^justify/.test(sb[1]) && on) anyAlign = true;
      sb[0].setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    // Aucun alignement explicite : le texte est justifié par défaut (style de la zone)
    if (inside && !anyAlign) {
      stateButtons.forEach(function (sb) { if (sb[1] === 'justifyFull') sb[0].setAttribute('aria-pressed', 'true'); });
    }
  }

  document.addEventListener('selectionchange', function () {
    saveRange();
    refreshState();
  });

  // mousedown : garde la sélection de l'éditeur (sinon le clic sur un bouton la ferait perdre)
  toolbar.addEventListener('mousedown', function (e) { if (e.target.closest('.rte__btn')) e.preventDefault(); });
  vars.addEventListener('mousedown', function (e) { if (e.target.closest('.rte__var')) e.preventDefault(); });

  toolbar.addEventListener('click', function (e) {
    var b = e.target.closest('.rte__btn');
    if (!b) return;
    restoreRange();
    var cmd = b.getAttribute('data-cmd');
    document.execCommand(cmd, false, null);
    if (cmd === 'removeFormat') document.execCommand('justifyFull', false, null);   // retour au justifié par défaut
    ensureParagraph();
    sync();
    refreshState();
  });

  vars.addEventListener('click', function (e) {
    var b = e.target.closest('.rte__var');
    if (!b) return;
    restoreRange();
    document.execCommand('insertText', false, b.getAttribute('data-var'));
    sync();
  });

  /* ==========================================================================
     Modèles de courrier : pré-remplissent l'objet et le corps
     ========================================================================== */

  var tplBox = document.querySelector('[data-courrier-template]');
  var tplSelect = tplBox && tplBox.querySelector('select');
  var subject = document.getElementById('subject');
  if (tplSelect) {
    var templates = {};
    try { templates = JSON.parse(tplSelect.getAttribute('data-templates') || '{}'); } catch (e) { /* aucun */ }
    tplBox.hidden = false;
    tplSelect.addEventListener('change', function () {
      var tpl = templates[tplSelect.value];
      if (!tpl) return;
      var hasContent = !isEmpty() || (subject && subject.value.trim() !== '' && tpl.subject !== '');
      if (hasContent && !window.confirm('Remplacer l\'objet et le corps actuels par le modèle « ' + tpl.label + ' » ?')) {
        tplSelect.value = '';
        return;
      }
      if (subject && tpl.subject !== '') {
        subject.value = tpl.subject;
        subject.dispatchEvent(new Event('input', { bubbles: true }));
      }
      setHtml(tpl.body);
      editor.focus();
    });
  }
})();
