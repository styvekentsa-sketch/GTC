<?php
/**
 * GTC-Vault — capture d'une pièce avec un téléphone : côté TÉLÉPHONE (ouvert en flashant le QR code du poste)
 *
 *   GET  capture.php?t=JETON   page de prise de vue (appareil photo arrière)
 *   POST capture.php?t=JETON   envoi de la photo (champ « photo ») → JSON
 *
 * - Aucune connexion au Dashboard : le jeton du QR code (256 bits, une pièce, 10 minutes) sert de clé ; il n'est
 *   conservé en base que sous forme d'empreinte. Il ne donne accès à AUCUNE donnée : il permet seulement de déposer
 *   une photo, que le poste de l'agent récupère puis efface (capture-api.php).
 * - Photo : JPEG ou PNG (type lu dans le contenu), 5 Mo au plus, rangée hors web (KYC_STORAGE_DIR/tmp).
 * - Ouverture et envoi journalisés (audit_logs) au nom de l'agent qui a généré le QR code.
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
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' blob:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$token   = (string) ($_GET['t'] ?? '');
$capture = capture_by_token($token);
$agent   = $capture ? db_one('SELECT id, name FROM users WHERE id = ?', [(int) $capture['created_by']]) : null;
$agent ??= ['id' => 0, 'name' => 'Agent'];

/** Réponse JSON de l'envoi. */
function capture_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Envoi de la photo ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$capture || !capture_is_open($capture)) {
        capture_reply(['error' => 'Ce QR code n\'est plus valide. Générez-en un nouveau sur le poste de l\'agent.'], 410);
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
    // Nouvelle prise de vue : la précédente (non encore récupérée) est remplacée
    capture_delete_file($capture['file_path']);
    db_query("UPDATE kyc_captures SET status = 'uploaded', file_path = ?, mime_type = ?, file_size = ?, uploaded_ts = ? WHERE id = ? AND status IN ('pending', 'opened', 'uploaded')",
        [$name, $mime, (int) $f['size'], time(), (int) $capture['id']]);
    vault_audit('capture_upload', null, null, 'Photo « ' . $capture['slot_label'] . ' » envoyée depuis un téléphone (' . vault_size((int) $f['size']) . ') pour ' . $agent['name'], $agent);
    capture_reply(['ok' => true]);
}

// ---------- Ouverture de la page ----------
$valid = $capture && capture_is_open($capture);
if ($valid && $capture['status'] === 'pending') {
    db_query("UPDATE kyc_captures SET status = 'opened', opened_ts = ?, phone_ip = ?, phone_ua = ? WHERE id = ? AND status = 'pending'",
        [time(), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), (int) $capture['id']]);
    vault_audit('capture_open', null, null, 'QR code « ' . $capture['slot_label'] . ' » ouvert sur un téléphone pour ' . $agent['name'], $agent);
}
$remaining = $valid ? max(0, (int) $capture['expires_ts'] - time()) : 0;
?>
<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#0f172a">
    <title>Capture sécurisée · GTC-Vault</title>
    <link rel="stylesheet" href="<?= h(vault_css()) ?>">
    <style>[hidden] { display: none !important; }</style>
</head>
<body class="min-h-full bg-slate-100 font-sans text-slate-900 antialiased">

<header class="bg-slate-900 px-4 py-3 text-white">
    <div class="mx-auto flex max-w-md items-center gap-2.5 font-semibold tracking-tight">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><circle cx="12" cy="10.5" r="2"/><path d="M12 12.5V16"/></svg>
        </span>
        GTC-Vault <span class="font-normal text-slate-400">· Capture sécurisée</span>
    </div>
</header>

<main class="mx-auto max-w-md px-4 pb-10 pt-5">
<?php if (!$valid): ?>
    <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 text-amber-600">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        </span>
        <h1 class="mt-4 text-lg font-semibold">Ce QR code n'est plus valide</h1>
        <p class="mt-1.5 text-sm text-slate-600">
            <?= $capture && $capture['status'] === 'consumed' ? 'La photo a déjà été transmise au poste de l\'agent.' : 'Il a expiré (10 minutes) ou a été remplacé.' ?>
            Si besoin, générez un nouveau QR code sur le poste de l'agent, puis flashez-le à nouveau.
        </p>
    </div>
<?php else: ?>
    <div id="step-shoot">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Pièce à photographier</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight"><?= h($capture['slot_label']) ?></h1>
        <p class="mt-1 text-sm text-slate-600">Demandée par <?= h($agent['name']) ?> · <span id="countdown" data-remaining="<?= $remaining ?>">valable <?= (int) ceil($remaining / 60) ?> min</span></p>

        <ul class="mt-5 space-y-2.5 rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-700 shadow-sm">
            <li class="flex gap-2.5"><svg class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M15 9h3M15 13h3M6 16h7"/></svg><span>Posez la pièce à plat, <strong>en entier</strong> dans le cadre.</span></li>
            <li class="flex gap-2.5"><svg class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg><span>Bonne lumière, <strong>sans reflet</strong> ni flash sur la photo.</span></li>
            <li class="flex gap-2.5"><svg class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg><span>Vérifiez que le texte est <strong>net et lisible</strong>.</span></li>
        </ul>

        <div id="preview-box" hidden class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <img id="preview" alt="Aperçu de la photo" class="max-h-[55vh] w-full bg-slate-900 object-contain">
            <p id="preview-meta" class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500"></p>
        </div>

        <p id="error" role="alert" hidden class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-inset ring-red-600/20"></p>

        <div class="mt-5 space-y-3">
            <label id="shoot-btn" class="flex w-full cursor-pointer items-center justify-center gap-2.5 rounded-2xl bg-indigo-600 px-5 py-4 text-lg font-semibold text-white shadow-lg shadow-indigo-600/30 active:scale-[.98]">
                <input type="file" id="camera" accept="image/jpeg,image/png,image/*" capture="environment" class="sr-only">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3.5"/></svg>
                <span data-text>Prendre la photo</span>
            </label>
            <button type="button" id="send-btn" hidden class="flex w-full items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-5 py-4 text-lg font-semibold text-white shadow-lg shadow-emerald-600/30 active:scale-[.98] disabled:opacity-70">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                <span data-text>Envoyer au poste de l'agent</span>
            </button>
            <div id="progress" hidden class="h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Envoi de la photo" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full w-0 rounded-full bg-emerald-500 transition-all"></div>
            </div>
            <label class="block cursor-pointer text-center text-sm font-medium text-slate-600 underline underline-offset-4">
                <input type="file" id="gallery" accept="image/jpeg,image/png,image/*" class="sr-only">
                ou choisir une photo existante
            </label>
        </div>
    </div>

    <div id="step-done" hidden class="rounded-2xl border border-emerald-200 bg-white p-6 text-center shadow-sm">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>
        </span>
        <h1 class="mt-4 text-xl font-semibold">Photo transmise</h1>
        <p class="mt-1.5 text-sm text-slate-600">La photo « <?= h($capture['slot_label']) ?> » est arrivée sur le poste de <?= h($agent['name']) ?>. Vous pouvez fermer cette page.</p>
        <p class="mt-4 text-xs text-slate-500">Photo à refaire ? Sur le poste, cliquez à nouveau sur « Scanner avec un mobile » : un nouveau QR code s'affiche.</p>
    </div>

    <p class="mt-6 flex items-start gap-2 text-xs leading-relaxed text-slate-500">
        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
        La photo est envoyée directement au dossier sécurisé ; rien n'est conservé dans le navigateur de ce téléphone.
    </p>

<script>
(function () {
  'use strict';
  var MAX = <?= KYC_MAX_BYTES ?>, LONG_EDGE = 2200, QUALITY = 0.85;
  var $ = function (id) { return document.getElementById(id); };
  var blob = null, previewUrl = null;

  // Compte à rebours de validité du QR code
  var left = parseInt($('countdown').getAttribute('data-remaining'), 10), end = Date.now() + left * 1000;
  var tick = setInterval(function () {
    var s = Math.max(0, Math.round((end - Date.now()) / 1000));
    $('countdown').textContent = s > 0 ? 'valable encore ' + Math.floor(s / 60) + ' min ' + String(s % 60).padStart(2, '0') + ' s' : 'QR code expiré';
    if (!s) { clearInterval(tick); showError('Ce QR code a expiré. Générez-en un nouveau sur le poste de l\'agent.'); $('send-btn').disabled = true; }
  }, 1000);

  function showError(msg) { $('error').textContent = msg; $('error').hidden = !msg; }
  function kb(n) { return n < 1048576 ? Math.round(n / 1024) + ' Ko' : (n / 1048576).toFixed(1).replace('.', ',') + ' Mo'; }

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
    if (!file) return;
    showError('');
    compress(file).then(function (b) {
      if (b.size > MAX) { showError('Photo trop lourde (' + kb(b.size) + ') : 5 Mo au maximum.'); return; }
      blob = b;
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = URL.createObjectURL(b);
      $('preview').src = previewUrl;
      $('preview-meta').textContent = 'Photo prête · ' + kb(b.size) + ' — vérifiez qu\'elle est nette avant l\'envoi.';
      $('preview-box').hidden = false;
      $('send-btn').hidden = false;
      $('shoot-btn').querySelector('[data-text]').textContent = 'Reprendre la photo';
      $('shoot-btn').classList.replace('bg-indigo-600', 'bg-slate-700');
      $('shoot-btn').classList.remove('shadow-lg');
      $('send-btn').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }
  $('camera').addEventListener('change', function () { choose(this); });
  $('gallery').addEventListener('change', function () { choose(this); });

  $('send-btn').addEventListener('click', function () {
    if (!blob) return;
    var btn = this, bar = $('progress'), fill = bar.firstElementChild;
    btn.disabled = true; btn.querySelector('[data-text]').textContent = 'Envoi en cours…'; bar.hidden = false; showError('');
    var fd = new FormData(); fd.append('photo', blob, 'photo.' + (blob.type === 'image/png' ? 'png' : 'jpg'));
    var xhr = new XMLHttpRequest();
    xhr.open('POST', location.href);
    xhr.upload.onprogress = function (e) { if (e.lengthComputable) { var p = Math.round(e.loaded / e.total * 100); fill.style.width = p + '%'; bar.setAttribute('aria-valuenow', p); } };
    xhr.onload = function () {
      var r = {}; try { r = JSON.parse(xhr.responseText); } catch (err) {}
      if (xhr.status === 200 && r.ok) { $('step-shoot').hidden = true; $('step-done').hidden = false; clearInterval(tick); return; }
      fail(r.error || 'L\'envoi a échoué. Vérifiez la connexion et réessayez.');
    };
    xhr.onerror = function () { fail('Connexion perdue pendant l\'envoi. Réessayez.'); };
    xhr.send(fd);
    function fail(msg) { showError(msg); btn.disabled = false; btn.querySelector('[data-text]').textContent = 'Réessayer l\'envoi'; bar.hidden = true; fill.style.width = '0'; }
  });
})();
</script>
<?php endif; ?>
</main>
</body>
</html>
