<?php
/**
 * GTC-Vault — capture des pièces avec un téléphone : côté TÉLÉPHONE (ouvert en flashant le QR code du poste)
 *
 *   GET  capture.php?t=JETON                  prise de vue, pièce après pièce (ex. CNI recto puis verso)
 *   POST capture.php?t=JETON  step, photo     envoi de la photo d'une pièce → JSON { ok, next }
 *   POST capture.php?t=JETON  skip            pièce facultative passée     → JSON { ok, next }
 *
 * - Aucune connexion au Dashboard : le jeton du QR code (256 bits, 15 minutes) sert de clé ; il n'est conservé en
 *   base que sous forme d'empreinte. Il ne donne accès à AUCUNE donnée : il permet seulement de déposer les photos
 *   de la session, que le poste de l'agent récupère puis efface (capture-api.php).
 * - Une photo peut être reprise tant que la session est ouverte : elle remplace la précédente sur le poste.
 * - Photo : JPEG ou PNG (type lu dans le contenu), 5 Mo au plus, rangée hors web (KYC_STORAGE_DIR/tmp).
 * - Ouverture et envois journalisés (audit_logs) au nom de l'agent qui a généré le QR code.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/../../includes/vault.php';

// Le jeton est dans l'adresse : jamais transmis à un autre site, jamais en cache, jamais indexé
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' blob:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$token = (string) ($_GET['t'] ?? '');
$first = capture_by_token($token);
if ($first && (int) $first['batch_id'] !== (int) $first['id']) {
    $first = null;   // seul le jeton de la 1re pièce d'une session ouvre la page
}
$steps = $first ? capture_batch((int) $first['id']) : [];
$agent = $first ? db_one('SELECT id, name FROM users WHERE id = ?', [(int) $first['created_by']]) : null;
$agent ??= ['id' => 0, 'name' => 'Agent'];
$valid = $first && capture_is_open($first);

/** Réponse JSON des envois. */
function capture_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Prochaine pièce encore à photographier (id), ou null. */
function capture_next(int $batchId): ?int
{
    $next = db_value("SELECT id FROM kyc_captures WHERE batch_id = ? AND status = 'pending' ORDER BY step LIMIT 1", [$batchId]);
    return $next !== null && $next !== false ? (int) $next : null;
}

// ---------- Envoi d'une photo / pièce facultative passée ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$valid) {
        capture_reply(['error' => 'Cette session de capture est terminée. Générez un nouveau QR code sur le poste de l\'agent.'], 410);
    }
    $stepId = (int) ($_POST['step'] ?? $_POST['skip'] ?? 0);
    $step   = null;
    foreach ($steps as $s) {
        if ((int) $s['id'] === $stepId) {
            $step = $s;
        }
    }
    if (!$step || $step['status'] === 'cancelled') {
        capture_reply(['error' => 'Pièce inconnue pour cette session.'], 404);
    }

    if (isset($_POST['skip'])) {
        if ((int) $step['required'] === 1) {
            capture_reply(['error' => 'Cette pièce est obligatoire.'], 422);
        }
        db_query("UPDATE kyc_captures SET status = 'skipped' WHERE id = ? AND status = 'pending'", [(int) $step['id']]);
        capture_reply(['ok' => true, 'next' => capture_next((int) $first['id'])]);
    }

    $f = $_FILES['photo'] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || ($f['size'] ?? 0) > KYC_MAX_BYTES) {
        capture_reply(['error' => 'Photo trop lourde (5 Mo au maximum).'], 413);
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] === 0) {
        capture_reply(['error' => 'La photo n\'a pas pu être reçue. Réessayez.'], 400);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        capture_reply(['error' => 'Format non pris en charge : prenez la photo avec l\'appareil photo (JPEG ou PNG).'], 415);
    }
    $dir = capture_tmp_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        error_log('[GTC-Vault capture] dossier provisoire inaccessible : ' . $dir);
        capture_reply(['error' => 'Le serveur n\'a pas pu enregistrer la photo.'], 500);
    }
    $name = bin2hex(random_bytes(16)) . ($mime === 'image/png' ? '.png' : '.jpg');
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        capture_reply(['error' => 'Le serveur n\'a pas pu enregistrer la photo.'], 500);
    }
    @chmod($dir . '/' . $name, 0640);
    // Photo reprise : la précédente (si le poste ne l'a pas encore récupérée) est remplacée
    capture_delete_file($step['file_path']);
    $retake = in_array($step['status'], ['uploaded', 'consumed'], true);
    db_query("UPDATE kyc_captures SET status = 'uploaded', file_path = ?, mime_type = ?, file_size = ?, uploaded_ts = ? WHERE id = ? AND status <> 'cancelled'",
        [$name, $mime, (int) $f['size'], time(), (int) $step['id']]);
    vault_audit('capture_upload', null, null, 'Photo « ' . $step['slot_label'] . ' » ' . ($retake ? 'reprise' : 'envoyée')
        . ' depuis un téléphone (' . vault_size((int) $f['size']) . ') pour ' . $agent['name'], $agent);
    capture_reply(['ok' => true, 'next' => capture_next((int) $first['id'])]);
}

// ---------- Ouverture de la page ----------
if ($valid && !$first['opened_ts']) {
    db_query('UPDATE kyc_captures SET opened_ts = ?, phone_ip = ?, phone_ua = ? WHERE id = ? AND opened_ts IS NULL',
        [time(), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), (int) $first['id']]);
    vault_audit('capture_open', null, null, 'QR code (' . implode(', ', array_column($steps, 'slot_label')) . ') ouvert sur un téléphone pour ' . $agent['name'], $agent);
}
$remaining = $valid ? max(0, (int) $first['expires_ts'] - time()) : 0;
$stepsJs = array_map(fn ($s) => ['id' => (int) $s['id'], 'label' => $s['slot_label'], 'required' => (int) $s['required'] === 1,
                                 'status' => $s['status']], $steps);
$root = site_root();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#1F2630">
    <title>Capture sécurisée · GTC-Vault</title>
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/fonts.css', $root)) ?>">
    <link rel="stylesheet" href="<?= h(asset_url('assets/css/dashboard.css', $root)) ?>">
    <link rel="stylesheet" href="<?= h(asset_url(VAULT_CSS, $root)) ?>">
</head>
<body class="cap-page">

<header class="cap-top">
    <div class="cap-top__inner">
        <img src="<?= h(asset_url('assets/img/logo-gtc-light-436.png', $root)) ?>" alt="<?= h(GTC_NAME) ?>" width="113" height="28">
        <span>GTC-Vault · Capture sécurisée</span>
    </div>
</header>

<main class="cap-main">
<?php if (!$valid): ?>
    <div class="cap-invalid">
        <span class="vault-empty__icon"><?= vault_icon('clock') ?></span>
        <h1>Ce QR code n'est plus valide</h1>
        <p>La session de capture est terminée : photos validées sur le poste de l'agent, QR code remplacé ou expiré (15 minutes).
           Si besoin, générez un nouveau QR code sur le poste, puis flashez-le à nouveau.</p>
    </div>
<?php else: ?>
    <!-- Progression -->
    <div class="cap-row">
        <ol id="progress-dots" class="cap-dots" aria-label="Pièces de la session"></ol>
        <span id="countdown" data-remaining="<?= $remaining ?>">valable <?= (int) ceil($remaining / 60) ?> min</span>
    </div>

    <div id="sent-banner" class="alert alert--success" role="status" hidden><?= icon('check') ?><p data-text></p></div>

    <!-- Prise de vue d'une pièce -->
    <section id="step-shoot">
        <p class="cap-overline" id="step-count">Pièce à photographier</p>
        <h1 class="cap-title" id="step-label"></h1>
        <p class="cap-meta">Demandée par <?= h($agent['name']) ?></p>

        <ul class="cap-card cap-tips">
            <li><?= vault_icon('id-card') ?><span>Posez la pièce à plat, <strong>en entier</strong> dans le cadre.</span></li>
            <li><?= vault_icon('sun') ?><span>Bonne lumière, <strong>sans reflet</strong> ni flash sur la photo.</span></li>
            <li><?= vault_icon('lens') ?><span>Vérifiez que le texte est <strong>net et lisible</strong>.</span></li>
        </ul>

        <div id="preview-box" class="cap-preview" hidden>
            <img id="preview" alt="Aperçu de la photo">
            <p id="preview-meta"></p>
        </div>

        <div id="error" class="alert alert--error" role="alert" hidden><?= icon('alert') ?><p data-text></p></div>

        <div class="cap-actions">
            <label id="shoot-btn" class="cap-btn">
                <input type="file" id="camera" accept="image/jpeg,image/png,image/*" capture="environment" class="visually-hidden">
                <?= vault_icon('camera') ?>
                <span data-text>Prendre la photo</span>
            </label>
            <button type="button" id="send-btn" class="cap-btn cap-btn--send" hidden>
                <?= vault_icon('send') ?>
                <span data-text>Envoyer au poste de l'agent</span>
            </button>
            <div id="progress" class="cap-progress" role="progressbar" aria-label="Envoi de la photo" aria-valuemin="0" aria-valuemax="100" hidden><span></span></div>
            <div class="cap-links">
                <label>
                    <input type="file" id="gallery" accept="image/jpeg,image/png,image/*" class="visually-hidden">
                    Choisir une photo existante
                </label>
                <button type="button" id="skip-btn" hidden>Passer cette pièce</button>
                <button type="button" id="back-recap" hidden>Annuler</button>
            </div>
        </div>
    </section>

    <!-- Vérification finale -->
    <section id="step-recap" hidden>
        <span class="cap-done-icon"><?= vault_icon('ok') ?></span>
        <h1 class="cap-title">Toutes les pièces sont transmises</h1>
        <p class="cap-meta">Vérifiez que chaque photo est nette et lisible. Elles sont déjà sur le poste de <?= h($agent['name']) ?> ; reprenez-en une si besoin.</p>
        <ul id="recap-list" class="cap-recap"></ul>
        <p class="cap-meta" style="margin-top: 1.25rem">Tout est bon ? Vous pouvez fermer cette page.</p>
    </section>

    <p class="vault-note cap-fine"><?= icon('lock') ?> Les photos sont envoyées directement au dossier sécurisé ; rien n'est conservé dans le navigateur de ce téléphone.</p>

<script>
(function () {
  'use strict';
  var MAX = <?= KYC_MAX_BYTES ?>, LONG_EDGE = 2200, QUALITY = 0.85;
  var STEPS = <?= json_encode($stepsJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var CHECK = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>';
  var $ = function (id) { return document.getElementById(id); };
  var blob = null, previewUrl = null, current = null, retaking = false, shots = {};   // shots[id] = URL locale de la photo envoyée
  var done = function (s) { return s.status === 'uploaded' || s.status === 'consumed' || s.status === 'skipped'; };

  // Compte à rebours de validité du QR code
  var end = Date.now() + parseInt($('countdown').getAttribute('data-remaining'), 10) * 1000;
  var tick = setInterval(function () {
    var s = Math.max(0, Math.round((end - Date.now()) / 1000));
    $('countdown').textContent = s > 0 ? 'valable encore ' + Math.floor(s / 60) + ' min ' + String(s % 60).padStart(2, '0') + ' s' : 'QR code expiré';
    if (!s) { clearInterval(tick); showError('Ce QR code a expiré. Générez-en un nouveau sur le poste de l\'agent.'); $('send-btn').disabled = true; }
  }, 1000);

  function showError(msg) { $('error').querySelector('[data-text]').textContent = msg; $('error').hidden = !msg; }
  function kb(n) { return n < 1048576 ? Math.round(n / 1024) + ' Ko' : (n / 1048576).toFixed(1).replace('.', ',') + ' Mo'; }
  function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function renderDots() {
    $('progress-dots').innerHTML = STEPS.map(function (s) {
      var on = current && s.id === current.id, ok = done(s);
      return '<li class="' + (on ? 'is-current' : ok ? 'is-done' : '') + '"><span class="visually-hidden">' + esc(s.label) + (ok ? ' : transmise' : '') + '</span></li>';
    }).join('');
  }

  /** Affiche la prise de vue d'une pièce (prochaine pièce, ou photo à reprendre). */
  function shoot(step, isRetake) {
    current = step; retaking = !!isRetake; blob = null;
    var n = STEPS.indexOf(step) + 1;
    $('step-count').textContent = isRetake ? 'Nouvelle photo' : 'Pièce ' + n + ' sur ' + STEPS.length + (step.required ? '' : ' · facultative');
    $('step-label').textContent = step.label;
    $('preview-box').hidden = true; $('send-btn').hidden = true; $('progress').hidden = true; showError('');
    var btn = $('shoot-btn');
    btn.querySelector('[data-text]').textContent = n > 1 && !isRetake ? 'Photographier : ' + step.label : 'Prendre la photo';
    btn.classList.remove('cap-btn--again');
    $('send-btn').disabled = false; $('send-btn').querySelector('[data-text]').textContent = 'Envoyer au poste de l\'agent';
    $('skip-btn').hidden = step.required || isRetake;
    $('back-recap').hidden = !isRetake;
    $('step-shoot').hidden = false; $('step-recap').hidden = true;
    renderDots();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /** Pièce suivante encore à photographier, ou vérification finale. */
  function advance() {
    var next = STEPS.filter(function (s) { return !done(s); })[0];
    if (next) shoot(next); else recap();
  }

  function recap() {
    current = null; renderDots();
    $('step-shoot').hidden = true; $('step-recap').hidden = false;
    $('recap-list').innerHTML = STEPS.map(function (s) {
      var skipped = s.status === 'skipped';
      var thumb = '<span class="cap-recap__thumb">' + (shots[s.id] ? '<img src="' + shots[s.id] + '" alt="">' : (skipped ? 'Passée' : 'Transmise')) + '</span>';
      return '<li>' + thumb + '<div class="cap-recap__body"><p class="cap-recap__label">' + esc(s.label) + '</p>'
        + '<p class="cap-recap__state' + (skipped ? ' is-skipped' : '') + '">' + (skipped ? 'Passée' : CHECK + ' Transmise') + '</p></div>'
        + '<button type="button" class="btn btn--ghost btn--sm" data-retake="' + s.id + '">' + (skipped ? 'Photographier' : 'Reprendre') + '</button></li>';
    }).join('');
    $('recap-list').querySelectorAll('[data-retake]').forEach(function (b) {
      b.addEventListener('click', function () { var id = +b.getAttribute('data-retake'); shoot(STEPS.filter(function (s) { return s.id === id; })[0], true); });
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /** Photo allégée pour l'envoi (côté le plus long : 2 200 px, JPEG) ; la photo d'origine si l'opération échoue. */
  function compress(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, k = Math.min(1, LONG_EDGE / Math.max(w, h));
        if (k === 1 && file.size <= MAX && file.type === 'image/jpeg') { URL.revokeObjectURL(url); resolve(file); return; }
        var c = document.createElement('canvas'); c.width = Math.round(w * k); c.height = Math.round(h * k);
        var ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b && b.size < file.size ? b : file); }, 'image/jpeg', QUALITY);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function choose(input) {
    var file = input.files && input.files[0];
    input.value = '';
    if (!file || !current) return;
    showError('');
    compress(file).then(function (b) {
      if (b.size > MAX) { showError('Photo trop lourde (' + kb(b.size) + ') : 5 Mo au maximum.'); return; }
      blob = b;
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = URL.createObjectURL(b);
      $('preview').src = previewUrl;
      $('preview-meta').textContent = 'Photo prête · ' + kb(b.size) + ' — vérifiez qu\'elle est nette avant l\'envoi.';
      $('preview-box').hidden = false; $('send-btn').hidden = false;
      var btn = $('shoot-btn');
      btn.querySelector('[data-text]').textContent = 'Reprendre la photo';
      btn.classList.add('cap-btn--again');
      $('send-btn').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }
  $('camera').addEventListener('change', function () { choose(this); });
  $('gallery').addEventListener('change', function () { choose(this); });

  function post(fd, onProgress) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', location.href);
      if (onProgress) xhr.upload.onprogress = onProgress;
      xhr.onload = function () { var r = {}; try { r = JSON.parse(xhr.responseText); } catch (e) {} xhr.status === 200 && r.ok ? resolve(r) : reject(new Error(r.error || 'L\'envoi a échoué. Vérifiez la connexion et réessayez.')); };
      xhr.onerror = function () { reject(new Error('Connexion perdue pendant l\'envoi. Réessayez.')); };
      xhr.send(fd);
    });
  }

  function banner(text) {
    var b = $('sent-banner'); b.querySelector('[data-text]').textContent = text; b.hidden = false;
    clearTimeout(banner.t); banner.t = setTimeout(function () { b.hidden = true; }, 4000);
  }

  $('send-btn').addEventListener('click', function () {
    if (!blob || !current) return;
    var btn = this, bar = $('progress'), fill = bar.firstElementChild, step = current;
    btn.disabled = true; btn.querySelector('[data-text]').textContent = 'Envoi en cours…'; bar.hidden = false; showError('');
    var fd = new FormData(); fd.append('step', step.id); fd.append('photo', blob, 'photo.' + (blob.type === 'image/png' ? 'png' : 'jpg'));
    post(fd, function (e) { if (e.lengthComputable) { var p = Math.round(e.loaded / e.total * 100); fill.style.width = p + '%'; bar.setAttribute('aria-valuenow', p); } })
      .then(function () {
        step.status = 'uploaded';
        if (shots[step.id]) URL.revokeObjectURL(shots[step.id]);
        shots[step.id] = previewUrl; previewUrl = null;
        banner(step.label + (retaking ? ' : nouvelle photo transmise' : ' transmise'));
        if (retaking) recap(); else advance();
      })
      .catch(function (e) { showError(e.message); btn.disabled = false; btn.querySelector('[data-text]').textContent = 'Réessayer l\'envoi'; bar.hidden = true; fill.style.width = '0'; });
  });

  $('skip-btn').addEventListener('click', function () {
    var step = current, fd = new FormData(); fd.append('skip', step.id);
    post(fd).then(function () { step.status = 'skipped'; banner(step.label + ' : pièce passée'); advance(); }).catch(function (e) { showError(e.message); });
  });
  $('back-recap').addEventListener('click', recap);

  advance();
})();
</script>
<?php endif; ?>
</main>
</body>
</html>
