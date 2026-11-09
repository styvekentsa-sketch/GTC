<?php
/**
 * GTC-Vault — Fiche d'un client KYC et consultation sécurisée de ses pièces
 *
 *   client.php?uuid=xxxxxxxx-xxxx-4xxx-xxxx-xxxxxxxxxxxx
 *
 * - Accès : session + rôle KYC (KYC_VIEW_ROLES) ; l'ouverture de la fiche est journalisée (audit_logs).
 * - Pièces : jamais de lien direct vers les fichiers (stockés hors de la racine web). « Consulter » ouvre un lecteur
 *   qui passe par viewer.php avec un lien signé valable 10 minutes dans cette session :
 *     · image : filigrane incrusté côté serveur dans les pixels ;
 *     · PDF   : rendu par PDF.js (hébergé dans assets/pdfjs) dans un canevas, filigrane peint sur chaque page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/vault.php';
require_once __DIR__ . '/../../includes/commercial_invoices.php';   // passerelle facturation (includes/kyc_link.php)

$user = vault_guard();   // connexion du Dashboard + rôle VAULT_ROLES

// Page sensible : jamais en cache, jamais dans un cadre d'un autre site
header('Cache-Control: no-store, private, max-age=0');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Referrer-Policy: same-origin');

/** Page « dossier introuvable » (aucun détail sur l'existence du dossier). */
function client_not_found(): never
{
    http_response_code(404);
    vault_header('GTC-Vault · Dossier introuvable');
    echo '<div class="toolbar"><a href="vault/clients.php" class="btn btn--ghost">' . icon('arrow-left') . ' Répertoire KYC</a></div>'
       . '<section class="panel"><div class="empty vault-empty" role="alert"><span class="vault-empty__icon">' . icon('search') . '</span>'
       . '<h2>Dossier introuvable</h2><p>Ce lien ne correspond à aucun dossier client. Il a peut-être été supprimé ou mal copié.</p></div></section>';
    dash_footer();
    exit;
}

$uuid = strtolower(trim((string) ($_GET['uuid'] ?? '')));
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
    client_not_found();
}

// ---------- Client et pièces (une requête, jointure) ----------
$st = vault_db()->prepare('SELECT c.id, c.uuid, c.type_client, c.nom_complet, c.telephone, c.email, c.adresse, c.numero_identifiant,
                                  c.created_at, c.updated_at, c.avatar_path, c.created_by, u.name AS created_by_name,
                                  d.id AS doc_id, d.type_document, d.nom_original, d.taille_fichier, d.mime_type, d.created_at AS doc_created_at
                           FROM clients c
                           LEFT JOIN users u ON u.id = c.created_by
                           LEFT JOIN client_documents d ON d.client_id = c.id
                           WHERE c.uuid = ?
                           ORDER BY d.created_at ASC, d.id ASC');
$st->execute([$uuid]);
$rows = $st->fetchAll();
if (!$rows) {
    vault_audit('client_not_found', null, null, 'Fiche demandée inexistante : ' . $uuid);
    client_not_found();
}
$client = $rows[0];
$docs = [];
foreach ($rows as $r) {
    if ($r['doc_id'] === null) {
        continue;
    }
    $isPdf = $r['mime_type'] === 'application/pdf';
    $docs[] = [
        'id'    => (int) $r['doc_id'],
        'type'  => $r['type_document'],
        'label' => KYC_DOC_LABELS[$r['type_document']] ?? 'Document',
        'name'  => (string) $r['nom_original'],
        'size'  => vault_size((int) $r['taille_fichier']),
        'date'  => vault_date($r['doc_created_at']),
        'kind'  => $isPdf ? 'pdf' : 'image',
        'src'   => vault_viewer_url((int) $r['doc_id'], $isPdf ? 'pdf' : 'image'),
    ];
}

// Pièces en image dans lesquelles recadrer la photo de profil (CNI recto et passeport en premier)
$order = ['cni_recto' => 0, 'passeport' => 1];
$photoDocs = array_values(array_filter($docs, fn ($d) => $d['kind'] === 'image' && $d['type'] !== 'cni_verso'));
usort($photoDocs, fn ($a, $b) => ($order[$a['type']] ?? 9) <=> ($order[$b['type']] ?? 9));
$photoDocs = array_map(fn ($d) => ['id' => $d['id'], 'type' => $d['type'], 'label' => $d['label'], 'src' => $d['src']], $photoDocs);

vault_audit('client_view', $client, null, $user['name'] . ' a ouvert la fiche du client ' . $client['nom_complet'] . ' (' . count($docs) . ' pièce' . (count($docs) > 1 ? 's' : '') . ')');

// Liens signés et jeton CSRF (photo de profil) créés : la session peut être libérée
$csrf = csrf_token();

// ---------- Complétude du dossier ----------
// Règle unique (includes/kyc_link.php), reprise par le badge de conformité des reçus et des factures
$missing = kyc_missing((string) $client['type_client'], array_column($docs, 'type'));

// ---------- Documents financiers liés (passerelle facturation : reçus de caisse, factures commerciales) ----------
$finance = [];
try {
    $finance = kyc_finance_documents((int) $client['id'], 20);
} catch (Throwable $e) {
    error_log('[GTC-Vault client] documents financiers : ' . $e->getMessage());
}
$canOpenFinance = in_array($user['role'] ?? '', OPERATION_ROLES, true);

// ---------- Dernières consultations de ce dossier ----------
$journal = [];
try {
    $st = vault_db()->prepare("SELECT created_at, user_name, action, details FROM audit_logs
                               WHERE client_uuid = ? AND action IN ('client_create', 'client_view', 'document_view', 'finance_link')
                               ORDER BY id DESC LIMIT 8");
    $st->execute([$uuid]);
    $journal = $st->fetchAll();
} catch (Throwable $e) {
    error_log('[GTC-Vault client] journal : ' . $e->getMessage());
}

$isCompany = $client['type_client'] === 'entreprise';
[$createdDay, $createdTime] = vault_date($client['created_at']);
$initials = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1),
    array_slice(array_values(array_filter(preg_split('/[\s&\-]+/u', $client['nom_complet']) ?: [],
        fn ($w) => preg_match('/^\p{L}/u', $w) && !in_array(mb_strtoupper($w), ['SARL', 'SA', 'SAS', 'SARLU', 'ETS', 'ET', 'DE', 'DU', 'LA', 'LE'], true)))
    , 0, 2))));

vault_header('GTC-Vault · ' . $client['nom_complet']);
session_write_close();   // en-tête affiché (messages flash consommés) : la session est libérée pour le lecteur
?>

<div class="toolbar">
    <a href="vault/clients.php" id="back-link" class="btn btn--ghost no-print"><?= icon('arrow-left') ?> Répertoire KYC</a>
    <div class="toolbar__actions no-print">
        <a href="vault/clients-nouveau.php" class="btn btn--ghost"><?= icon('plus') ?> Nouveau dossier</a>
        <!-- Dossier complet imprimé (print.php : papier à en-tête, identité, pièces filigranées), impression journalisée -->
        <a href="vault/print.php?uuid=<?= h($client['uuid']) ?>" class="btn btn--primary" data-print-dossier target="_blank" rel="noopener">
            <span data-icon><?= icon('printer') ?></span><span class="vault-spin" data-spinner hidden aria-hidden="true"></span>
            <span data-label>Imprimer le dossier complet</span>
        </a>
    </div>
</div>

<!-- ==================== EN-TÊTE DU DOSSIER ==================== -->
<article class="panel" aria-labelledby="client-name">
    <header class="detail-head">
        <div class="vault-head">
            <!-- Photo de profil (recadrée dans la pièce d'identité) ou initiales -->
            <div class="vault-head__photo" id="photo" data-initials="<?= h($initials ?: '?') ?>">
                <?= avatar_html($client, $initials, 'lg') ?>
                <?php if ($photoDocs): ?>
                    <button type="button" class="vault-head__edit no-print" data-avatar-edit title="Photo de profil"
                            aria-label="<?= $client['avatar_path'] ? 'Modifier la photo de profil' : 'Définir la photo de profil' ?>"><?= vault_icon('camera') ?></button>
                <?php endif; ?>
            </div>
            <div class="vault-head__main">
                <p class="detail-head__label">Dossier KYC</p>
                <div class="vault-head__title">
                    <h2 id="client-name" class="detail-head__number"><?= h($client['nom_complet']) ?></h2>
                    <?= $isCompany ? '<span class="badge badge--info" data-type-badge>Entreprise</span>' : '<span class="badge badge--success" data-type-badge>Particulier</span>' ?>
                </div>
                <p class="vault-head__meta">Dossier créé le <?= h($createdDay) ?> à <?= h($createdTime) ?> · Réf. <span class="vault-mono"><?= h($client['uuid']) ?></span></p>
                <?php if ($photoDocs && !$client['avatar_path']): ?>
                    <button type="button" class="vault-head__photo-link no-print" data-avatar-edit>
                        <?= vault_icon('user') ?> Définir la photo de profil à partir de la pièce d'identité
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($missing): ?>
            <div class="alert alert--warning vault-head__state" data-completeness>
                <?= icon('alert') ?>
                <div><p><strong>Dossier incomplet</strong></p><p>Manque : <?= h(implode(', ', $missing)) ?></p></div>
            </div>
        <?php else: ?>
            <span class="badge badge--success vault-head__state" data-completeness><?= icon('check') ?> Dossier complet</span>
        <?php endif; ?>
    </header>

    <!-- Coordonnées -->
    <div class="detail-grid vault-detail">
        <dl class="detail-list">
            <div><dt>Téléphone</dt><dd><a href="tel:<?= h($client['telephone']) ?>"><?= h(vault_phone($client['telephone'])) ?></a></dd></div>
            <div><dt>E-mail</dt><dd><?= $client['email'] ? '<a href="mailto:' . h($client['email']) . '">' . h($client['email']) . '</a>' : '<span class="vault-muted">Non renseigné</span>' ?></dd></div>
            <div><dt><?= $isCompany ? 'N° RCCM' : 'N° CNI / passeport' ?></dt>
                <dd><span class="vault-copy">
                    <span class="vault-mono" id="client-id-number"><?= $client['numero_identifiant'] !== null ? h($client['numero_identifiant']) : '—' ?></span>
                    <?php if ($client['numero_identifiant'] !== null): ?>
                        <button type="button" class="icon-btn no-print" data-copy="#client-id-number" aria-label="Copier le numéro" title="Copier"><?= vault_icon('copy') ?></button>
                    <?php endif; ?>
                </span></dd></div>
            <div><dt>Adresse</dt><dd><?= $client['adresse'] ? nl2br(h($client['adresse'])) : '<span class="vault-muted">Non renseignée</span>' ?></dd></div>
        </dl>
        <dl class="detail-list">
            <div><dt>Type de client</dt><dd><?= $isCompany ? 'Entreprise (personne morale)' : 'Particulier (personne physique)' ?></dd></div>
            <div><dt>Pièces versées</dt><dd><?= count($docs) ?></dd></div>
            <div><dt>Enregistré le</dt><dd><?= h($createdDay) ?> à <?= h($createdTime) ?></dd></div>
            <div><dt>Créé par</dt><dd data-created-by><?= h($client['created_by_name'] ?: 'Compte supprimé (n° ' . (int) $client['created_by'] . ')') ?></dd></div>
            <div><dt>Dernière mise à jour</dt><dd><?= h(implode(' à ', vault_date($client['updated_at']))) ?></dd></div>
        </dl>
    </div>
</article>

<div class="dash-grid dash-grid--aside">
    <!-- ==================== PIÈCES JUSTIFICATIVES ==================== -->
    <section class="panel" aria-labelledby="docs-title">
        <header class="panel__head">
            <h2 class="panel__title" id="docs-title"><?= icon('file') ?> Pièces Justificatives du Dossier</h2>
            <span class="cell-sub"><?= count($docs) ?> pièce<?= count($docs) > 1 ? 's' : '' ?> · consultation filigranée et enregistrée</span>
        </header>
        <?php if (!$docs): ?>
            <p class="empty">Aucune pièce n'a encore été versée à ce dossier.</p>
        <?php else: ?>
            <ul class="vault-docs">
                <?php foreach ($docs as $d): ?>
                    <li class="vault-doc">
                        <div class="vault-doc__top">
                            <span class="vault-doc__icon<?= $d['kind'] === 'pdf' ? ' vault-doc__icon--pdf' : '' ?>"><?= vault_icon($d['kind'] === 'pdf' ? 'pdf' : 'image') ?></span>
                            <div class="vault-doc__body">
                                <p class="vault-doc__title"><?= h($d['label']) ?></p>
                                <p class="vault-doc__name" title="<?= h($d['name']) ?>"><?= h($d['name']) ?></p>
                                <p class="vault-doc__meta"><?= $d['kind'] === 'pdf' ? 'PDF' : 'Image' ?> · <?= h($d['size']) ?> · ajoutée le <?= h($d['date'][0]) ?></p>
                            </div>
                        </div>
                        <button type="button" class="btn btn--primary btn--sm btn--block no-print"
                                data-view data-kind="<?= h($d['kind']) ?>" data-src="<?= h($d['src']) ?>"
                                data-label="<?= h($d['label']) ?>" data-meta="<?= h(($d['kind'] === 'pdf' ? 'PDF' : 'Image') . ' · ' . $d['size'] . ' · ajoutée le ' . $d['date'][0] . ' à ' . $d['date'][1]) ?>">
                            <?= icon('eye') ?> Consulter / Prévisualiser
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <!-- ==================== JOURNAL DES CONSULTATIONS ==================== -->
    <section class="panel no-print" aria-labelledby="audit-title">
        <header class="panel__head">
            <h2 class="panel__title" id="audit-title"><?= icon('history') ?> Dernières consultations</h2>
        </header>
        <p class="vault-note">Chaque ouverture de la fiche et de ses pièces est enregistrée.</p>
        <ol class="vault-journal" style="margin-top: 1rem">
            <?php foreach ($journal as $j):
                [$jd, $jt] = vault_date($j['created_at']);
                [$dot, $verb] = ['document_view' => ['doc', 'a consulté une pièce'], 'client_create' => ['create', 'a créé le dossier'],
                    'finance_link' => ['create', 'a émis un document financier']][$j['action']] ?? ['', 'a ouvert la fiche']; ?>
                <li>
                    <span class="vault-journal__dot<?= $dot ? ' vault-journal__dot--' . $dot : '' ?>" aria-hidden="true"></span>
                    <div>
                        <p><strong><?= h($j['user_name']) ?></strong> <?= $verb ?></p>
                        <p class="cell-sub"><?= h($jd) ?> à <?= h($jt) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
</div>

<!-- ==================== DOCUMENTS FINANCIERS LIÉS (traçabilité croisée) ==================== -->
<section class="panel no-print" aria-labelledby="finance-title" data-finance>
    <header class="panel__head">
        <h2 class="panel__title" id="finance-title"><?= icon('receipt') ?> Documents financiers liés</h2>
        <span class="cell-sub">Reçus de caisse et factures émis pour ce client · état du dossier KYC à l'émission</span>
    </header>
    <?php if (!$finance): ?>
        <p class="empty">Aucun reçu ni facture n'a encore été rattaché à ce dossier.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--stack">
                <thead><tr><th>Document</th><th>Date</th><th class="num">Montant</th><th>Agent</th><th>Dossier KYC à l'émission</th></tr></thead>
                <tbody>
                    <?php foreach ($finance as $f): [$fd, $ft] = vault_date($f['date']); ?>
                        <tr>
                            <td data-label="Document"><span class="cell-stack">
                                <?= $canOpenFinance ? '<a href="' . h($f['url']) . '" class="link-strong">' . h($f['number']) . '</a>' : '<strong>' . h($f['number']) . '</strong>' ?>
                                <small class="cell-sub"><?= h($f['label']) ?></small></span></td>
                            <td data-label="Date"><?= h($fd) ?> à <?= h($ft) ?></td>
                            <td data-label="Montant" class="num"><?= h($f['amount']) ?></td>
                            <td data-label="Agent"><?= h($f['agent']) ?></td>
                            <td data-label="Dossier KYC"><?= $f['kyc'] ? kyc_badge($f['kyc'], [], true) : '<span class="cell-sub">—</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- ==================== LECTEUR SÉCURISÉ ==================== -->
<dialog id="viewer" class="vault-viewer" aria-labelledby="viewer-title">
    <header class="vault-viewer__head">
        <div>
            <h2 id="viewer-title" class="vault-viewer__title"></h2>
            <p id="viewer-meta" class="vault-viewer__meta"></p>
        </div>
        <button type="button" class="vault-viewer__close" data-close aria-label="Fermer le lecteur"><?= vault_icon('x') ?></button>
    </header>
    <div id="viewer-stage" class="vault-viewer__stage">
        <div id="viewer-loading" class="vault-viewer__state"><span class="vault-spin"></span>Chargement sécurisé…</div>
        <div id="viewer-error" class="vault-viewer__state" hidden>
            <p data-text></p>
            <button type="button" class="btn btn--ghost btn--sm" onclick="location.reload()"><?= icon('refresh') ?> Recharger la fiche</button>
        </div>
        <div id="viewer-content" class="vault-viewer__content"></div>
    </div>
    <footer class="vault-viewer__foot">
        <?= icon('shield') ?>
        <span>Document confidentiel — usage interne. Consultation par <strong><?= h($user['name']) ?></strong> enregistrée dans le journal d'audit.</span>
    </footer>
</dialog>

<?php if ($photoDocs): ?>
<!-- ==================== PHOTO DE PROFIL : RECADRAGE DANS LA PIÈCE D'IDENTITÉ ==================== -->
<dialog id="avatar-dialog" class="vault-dialog vault-dialog--wide" aria-labelledby="avatar-title">
    <div class="vault-dialog__head">
        <span class="vault-dialog__icon"><?= vault_icon('user') ?></span>
        <div>
            <h2 id="avatar-title">Photo de profil</h2>
            <p>Placez le cadre sur le visage, sur la photo de la pièce d'identité.</p>
        </div>
        <button type="button" class="icon-btn" data-avatar-close aria-label="Fermer"><?= vault_icon('x') ?></button>
    </div>

    <?php if (count($photoDocs) > 1): ?>
        <div class="vault-segbtns" role="group" aria-label="Pièce source">
            <?php foreach ($photoDocs as $i => $pd): ?>
                <button type="button" data-avatar-doc="<?= $i ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><?= h($pd['label']) ?></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="crop-layout">
        <div id="crop-stage" class="crop-stage">
            <img id="crop-img" alt="" draggable="false">
            <div id="crop-box" class="crop-box" hidden tabindex="0" role="group" aria-label="Cadre de la photo : flèches pour déplacer, + et − pour agrandir ou réduire">
                <span class="crop-box__ring"></span>
                <span class="crop-box__handle" data-handle></span>
            </div>
            <div id="crop-loading" class="crop-loading"><span class="vault-spin"></span></div>
        </div>
        <div class="crop-side">
            <p class="vault-overline">Aperçu</p>
            <canvas id="crop-preview" class="crop-preview" width="224" height="224"></canvas>
            <label>Taille du cadre
                <input type="range" id="crop-zoom" min="8" max="100" value="40">
            </label>
            <p>Le filigrane de l'aperçu n'apparaît pas sur la photo enregistrée.</p>
        </div>
    </div>

    <p id="avatar-error" class="alert alert--error" role="alert" hidden></p>

    <div class="vault-dialog__actions">
        <?php if ($client['avatar_path']): ?>
            <button type="button" class="vault-link-btn vault-link-btn--danger" data-avatar-delete>Retirer la photo</button>
        <?php endif; ?>
        <span class="vault-dialog__end">
            <button type="button" class="btn btn--ghost btn--sm" data-avatar-close>Annuler</button>
            <button type="button" class="btn btn--primary btn--sm" data-avatar-save disabled><?= icon('save') ?> Enregistrer la photo</button>
        </span>
    </div>
</dialog>
<?php endif; ?>

<script src="vault/assets/pdfjs/pdf.min.js"></script>
<script>
(function () {
  'use strict';

  var AGENT = <?= json_encode($user['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var REF = <?= json_encode(substr($client['uuid'], 0, 8)) ?>;
  var dialog = document.getElementById('viewer');
  var stage = document.getElementById('viewer-stage');
  var content = document.getElementById('viewer-content');
  var loading = document.getElementById('viewer-loading');
  var errorBox = document.getElementById('viewer-error');
  var session = 0, pdfDoc = null, opener = null;

  if (window.pdfjsLib) pdfjsLib.GlobalWorkerOptions.workerSrc = 'vault/assets/pdfjs/pdf.worker.min.js';

  /** Texte du filigrane, à l'heure de Douala au moment de la consultation. */
  function watermarkText() {
    var now = new Intl.DateTimeFormat('fr-FR', { timeZone: 'Africa/Douala', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date()).replace(',', '');
    return 'GTC - USAGE INTERNE - ' + AGENT + ' - ' + now;
  }

  /** Filigrane peint dans le canevas (même dessin que viewer.php pour les images). */
  function paintWatermark(canvas, pageInfo) {
    var ctx = canvas.getContext('2d'), W = canvas.width, H = canvas.height, text = watermarkText();
    var size = Math.max(14, Math.round(Math.min(W, H) / 30));
    ctx.save();
    ctx.font = '700 ' + size + 'px Arial, sans-serif';
    var step = ctx.measureText(text).width + size * 4;
    ctx.translate(W / 2, H / 2); ctx.rotate(-Math.PI / 6); ctx.translate(-W / 2, -H / 2);
    for (var y = -H, row = 0; y < H * 2; y += size * 7, row++) {
      for (var x = -W + (row % 2) * step / 2; x < W * 2; x += step) {
        ctx.fillStyle = 'rgba(255,255,255,.25)'; ctx.fillText(text, x + 1, y + 1);
        ctx.fillStyle = 'rgba(15,23,42,.32)'; ctx.fillText(text, x, y);
      }
    }
    ctx.restore();
    var fs = Math.max(11, Math.round(size * 0.7)), band = Math.round(fs * 2.6);
    ctx.fillStyle = 'rgba(15,23,42,.78)'; ctx.fillRect(0, H - band, W, band);
    ctx.fillStyle = '#fff'; ctx.font = '700 ' + fs + 'px Arial, sans-serif';
    ctx.fillText(text + '  ·  Réf. ' + REF + ' · ' + pageInfo + ' · consultation enregistrée', Math.round(fs * 0.9), H - Math.round(band * 0.32));
  }

  function fail(message) {
    loading.hidden = true;
    errorBox.hidden = false;
    errorBox.querySelector('[data-text]').textContent = message;
  }

  function reset() {
    session++;
    if (pdfDoc) { pdfDoc.destroy(); pdfDoc = null; }
    content.innerHTML = ''; errorBox.hidden = true; loading.hidden = false;
  }

  // ---------- Image : déjà filigranée par le serveur ----------
  function showImage(src, label) {
    var img = new Image();
    img.alt = label + ' (aperçu filigrané)';
    img.draggable = false;
    img.onload = function () { loading.hidden = true; };
    img.onerror = function () { fail('L\'aperçu n\'a pas pu être chargé. Le lien de consultation a peut-être expiré (10 minutes) : rechargez la fiche.'); };
    img.src = src;
    content.appendChild(img);
  }

  // ---------- PDF : rendu page par page dans des canevas ----------
  function showPdf(src) {
    var mine = session;
    if (!window.pdfjsLib) { fail('Le lecteur PDF n\'a pas pu être chargé.'); return; }
    fetch(src, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-GTC-Viewer': '1' } })
      .then(function (r) {
        if (!r.ok) throw new Error(r.status === 403 ? 'expired' : 'http');
        return r.arrayBuffer();
      })
      .then(function (data) {
        // isEvalSupported:false : aucun code généré par le document n'est exécuté (protection contre les PDF piégés)
        return pdfjsLib.getDocument({ data: new Uint8Array(data), isEvalSupported: false, enableXfa: false, disableAutoFetch: true }).promise;
      })
      .then(function (pdf) {
        if (mine !== session) { pdf.destroy(); return; }
        pdfDoc = pdf;
        var total = Math.min(pdf.numPages, 30), chain = Promise.resolve();
        var width = Math.min(stage.clientWidth - 40, 1000);
        for (var n = 1; n <= total; n++) {
          (function (n) {
            chain = chain.then(function () {
              if (mine !== session) return;
              return pdf.getPage(n).then(function (page) {
                var base = page.getViewport({ scale: 1 });
                var dpr = Math.min(window.devicePixelRatio || 1, 2);
                var vp = page.getViewport({ scale: (width / base.width) * dpr });
                var canvas = document.createElement('canvas');
                canvas.width = Math.floor(vp.width); canvas.height = Math.floor(vp.height);
                canvas.style.width = Math.floor(vp.width / dpr) + 'px';
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', 'Page ' + n + ' sur ' + pdf.numPages + ' (aperçu filigrané)');
                return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise.then(function () {
                  if (mine !== session) return;
                  paintWatermark(canvas, 'page ' + n + '/' + pdf.numPages);   // filigrane peint AVANT l'affichage
                  content.appendChild(canvas);
                  loading.hidden = true;
                });
              });
            });
          })(n);
        }
        return chain.then(function () {
          if (pdf.numPages > total && mine === session) {
            var p = document.createElement('p');
            p.textContent = total + ' premières pages affichées sur ' + pdf.numPages + '.'; content.appendChild(p);
          }
        });
      })
      .catch(function (e) {
        if (mine !== session) return;
        fail(e.message === 'expired'
          ? 'Le lien de consultation a expiré (10 minutes). Rechargez la fiche pour consulter cette pièce.'
          : 'Le document n\'a pas pu être affiché.');
      });
  }

  document.querySelectorAll('[data-view]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      reset();
      opener = btn;
      document.getElementById('viewer-title').textContent = btn.getAttribute('data-label');
      document.getElementById('viewer-meta').textContent = btn.getAttribute('data-meta');
      dialog.showModal();
      if (btn.getAttribute('data-kind') === 'pdf') showPdf(btn.getAttribute('data-src'));
      else showImage(btn.getAttribute('data-src'), btn.getAttribute('data-label'));
    });
  });
  dialog.querySelector('[data-close]').addEventListener('click', function () { dialog.close(); });
  dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });   // clic sur le fond
  dialog.addEventListener('close', function () { reset(); loading.hidden = true; if (opener) opener.focus(); });

  // Pas de menu « Enregistrer l'image », de glisser-déposer ni de copie depuis le lecteur
  ['contextmenu', 'dragstart', 'copy'].forEach(function (t) { stage.addEventListener(t, function (e) { e.preventDefault(); }); });
  // Impression : le lecteur est masqué (feuille de style @media print) ; on le ferme aussi
  window.addEventListener('beforeprint', function () { if (dialog.open) dialog.close(); });

  // Retour à la liste : on revient à la recherche en cours si l'on vient du répertoire
  document.getElementById('back-link').addEventListener('click', function (e) {
    try {
      var ref = new URL(document.referrer);
      if (ref.origin === location.origin && /\/clients\.php$/.test(ref.pathname) && history.length > 1) { e.preventDefault(); history.back(); }
    } catch (err) { /* pas de page précédente : lien normal */ }
  });

  // ---------- Impression du dossier complet ----------
  // Le document (vault/print.php) est préparé dans un cadre invisible : pièces filigranées chargées, puis fenêtre
  // d'impression du navigateur. L'impression est journalisée par le serveur à l'ouverture du document.
  // Sans cadre utilisable (navigateur restrictif) : le document s'ouvre dans un nouvel onglet, avec son bouton Imprimer.
  var printBtn = document.querySelector('[data-print-dossier]'), printFrame = null, printTimer = null;
  function printBusy(on) {
    printBtn.classList.toggle('is-busy', on);
    printBtn.setAttribute('aria-disabled', on ? 'true' : 'false');
    printBtn.querySelector('[data-icon]').hidden = on;
    printBtn.querySelector('[data-spinner]').hidden = !on;
    printBtn.querySelector('[data-label]').textContent = on ? 'Préparation de l\'impression…' : 'Imprimer le dossier complet';
  }
  printBtn.addEventListener('click', function (e) {
    if (e.ctrlKey || e.metaKey || e.shiftKey) return;           // ouverture volontaire dans un onglet
    e.preventDefault();
    if (printBtn.classList.contains('is-busy')) return;
    printBusy(true);
    if (printFrame) printFrame.remove();
    printFrame = document.createElement('iframe');
    printFrame.title = 'Dossier à imprimer';
    printFrame.setAttribute('aria-hidden', 'true');
    printFrame.className = 'vault-print-frame';
    printFrame.src = printBtn.getAttribute('href') + '&auto=1';
    document.body.appendChild(printFrame);
    clearTimeout(printTimer);
    printTimer = setTimeout(function () {                        // rien après 45 s : document ouvert dans un onglet
      if (!printBtn.classList.contains('is-busy')) return;
      printBusy(false); window.open(printBtn.href, '_blank', 'noopener');
    }, 45000);
  });
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || !e.data || e.data.source !== 'gtc-vault-print') return;
    if (e.data.type === 'printing' || e.data.type === 'done') { clearTimeout(printTimer); printBusy(false); }
  });

  // Ctrl+P sur la fiche : la page s'imprime sans menus ni boutons (feuille @media print) ; l'impression est journalisée
  window.addEventListener('beforeprint', function () {
    var fd = new FormData();
    fd.append('csrf_token', <?= json_encode($csrf) ?>); fd.append('uuid', <?= json_encode($client['uuid']) ?>); fd.append('log', 'shortcut');
    if (navigator.sendBeacon) navigator.sendBeacon('vault/print.php', fd);
  });

  // Copier le N° d'identifiant
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var text = document.querySelector(b.getAttribute('data-copy')).textContent.trim();
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
        b.setAttribute('title', 'Copié !'); b.classList.add('is-copied');
        setTimeout(function () { b.setAttribute('title', 'Copier'); b.classList.remove('is-copied'); }, 1500);
      }, function () {});
    });
  });

  // ---------- Photo de profil : recadrage dans la pièce d'identité ----------
  var av = document.getElementById('avatar-dialog');
  if (!av) return;
  var DOCS = <?= json_encode($photoDocs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var CSRF = <?= json_encode($csrf) ?>, UUID = <?= json_encode($client['uuid']) ?>;
  var img = document.getElementById('crop-img'), box = document.getElementById('crop-box'), stageEl = document.getElementById('crop-stage');
  var zoom = document.getElementById('crop-zoom'), preview = document.getElementById('crop-preview'), saveBtn = av.querySelector('[data-avatar-save]');
  var saveHtml = saveBtn.innerHTML;
  var errBox = document.getElementById('avatar-error');
  var current = 0, crop = null;   // crop : { x, y, s } en fractions de la LARGEUR affichée (x, s) et de la HAUTEUR (y)

  /** Cadre proposé : emplacement habituel de la photo sur la pièce (CNI : à gauche ; passeport : page photo). */
  function defaultCrop(type, ratio) {           // ratio = hauteur / largeur de l'image
    var s, x, yc;
    if (type === 'cni_recto') { s = 0.27; x = 0.04; yc = 0.56; }
    else if (type === 'passeport') { s = 0.26; x = 0.05; yc = 0.52; }
    else { s = Math.min(0.5, 0.5 * ratio); x = (1 - s) / 2; yc = 0.5; }
    var y = yc - (s / ratio) / 2;                  // s est relatif à la largeur : en hauteur il vaut s / ratio
    return clampCrop({ x: x, y: y, s: s }, ratio);
  }
  function clampCrop(c, ratio) {
    var maxS = Math.min(1, ratio);                 // le carré tient dans la largeur et dans la hauteur
    c.s = Math.max(0.05, Math.min(c.s, maxS));
    c.x = Math.max(0, Math.min(c.x, 1 - c.s));
    c.y = Math.max(0, Math.min(c.y, 1 - c.s / ratio));
    return c;
  }
  function ratio() { return img.naturalHeight / img.naturalWidth || 1; }

  function render() {
    if (!crop) return;
    var W = img.clientWidth, H = img.clientHeight;
    box.style.left = (crop.x * W) + 'px'; box.style.top = (crop.y * H) + 'px';
    box.style.width = box.style.height = (crop.s * W) + 'px';
    zoom.value = Math.round(crop.s / Math.min(1, ratio()) * 100);
    var ctx = preview.getContext('2d'), N = img.naturalWidth;
    ctx.clearRect(0, 0, preview.width, preview.height);
    ctx.drawImage(img, crop.x * N, crop.y * img.naturalHeight, crop.s * N, crop.s * N, 0, 0, preview.width, preview.height);
  }

  function load(i) {
    current = i; crop = null; box.hidden = true; saveBtn.disabled = true; errBox.hidden = true;
    document.getElementById('crop-loading').hidden = false;
    av.querySelectorAll('[data-avatar-doc]').forEach(function (b) { b.setAttribute('aria-pressed', String(+b.getAttribute('data-avatar-doc') === i)); });
    img.onload = function () {
      document.getElementById('crop-loading').hidden = true;
      crop = defaultCrop(DOCS[i].type, ratio());
      box.hidden = false; saveBtn.disabled = false;
      render(); box.focus({ preventScroll: true });
    };
    img.onerror = function () {
      document.getElementById('crop-loading').hidden = true;
      showErr('La pièce n\'a pas pu être chargée (lien expiré au bout de 10 minutes) : rechargez la fiche.');
    };
    img.src = DOCS[i].src;
  }
  function showErr(m) { errBox.textContent = m; errBox.hidden = !m; }

  // Déplacement et redimensionnement (souris, doigt, stylet)
  var drag = null;
  box.addEventListener('pointerdown', function (e) {
    e.preventDefault(); box.setPointerCapture(e.pointerId);
    drag = { mode: e.target.hasAttribute('data-handle') ? 'resize' : 'move', x0: e.clientX, y0: e.clientY, c: { x: crop.x, y: crop.y, s: crop.s } };
  });
  box.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var W = img.clientWidth, H = img.clientHeight, dx = (e.clientX - drag.x0), dy = (e.clientY - drag.y0);
    if (drag.mode === 'move') crop = clampCrop({ x: drag.c.x + dx / W, y: drag.c.y + dy / H, s: drag.c.s }, ratio());
    else crop = clampCrop({ x: drag.c.x, y: drag.c.y, s: drag.c.s + Math.max(dx, dy) / W }, ratio());
    render();
  });
  ['pointerup', 'pointercancel'].forEach(function (t) { box.addEventListener(t, function () { drag = null; }); });
  // Clavier : flèches (Maj : pas plus grand), + / − pour la taille
  box.addEventListener('keydown', function (e) {
    var st = e.shiftKey ? 0.05 : 0.01, c = { x: crop.x, y: crop.y, s: crop.s };
    if (e.key === 'ArrowLeft') c.x -= st; else if (e.key === 'ArrowRight') c.x += st;
    else if (e.key === 'ArrowUp') c.y -= st; else if (e.key === 'ArrowDown') c.y += st;
    else if (e.key === '+' || e.key === '=') { c.s += st; c.x -= st / 2; c.y -= st / 2 / ratio(); }
    else if (e.key === '-') { c.s -= st; c.x += st / 2; c.y += st / 2 / ratio(); }
    else return;
    e.preventDefault(); crop = clampCrop(c, ratio()); render();
  });
  // Taille du cadre (autour de son centre)
  zoom.addEventListener('input', function () {
    if (!crop) return;
    var r = ratio(), s = Math.min(1, r) * zoom.value / 100, cx = crop.x + crop.s / 2, cy = crop.y + crop.s / r / 2;
    crop = clampCrop({ x: cx - s / 2, y: cy - s / r / 2, s: s }, r); render();
  });
  if (window.ResizeObserver) new ResizeObserver(render).observe(stageEl);

  function openAvatar() { showErr(''); av.showModal(); load(0); }
  document.querySelectorAll('[data-avatar-edit]').forEach(function (b) { b.addEventListener('click', openAvatar); });
  av.querySelectorAll('[data-avatar-doc]').forEach(function (b) { b.addEventListener('click', function () { load(+b.getAttribute('data-avatar-doc')); }); });
  av.querySelectorAll('[data-avatar-close]').forEach(function (b) { b.addEventListener('click', function () { av.close(); }); });
  av.addEventListener('click', function (e) { if (e.target === av) av.close(); });

  function post(data) {
    var fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('c', UUID);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('vault/avatar.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (d) { if (!r.ok || !d.ok) throw new Error(d.error || 'Erreur'); return d; }); });
  }
  saveBtn.addEventListener('click', function () {
    if (!crop) return;
    saveBtn.disabled = true; saveBtn.textContent = 'Enregistrement…'; showErr('');
    post({ action: 'save', doc: DOCS[current].id, x: crop.x.toFixed(5), y: crop.y.toFixed(5), size: crop.s.toFixed(5) })
      .then(function (d) {
        // Avatar de l'en-tête remplacé par la nouvelle photo
        var holder = document.getElementById('photo'), old = holder.querySelector('[data-avatar]');
        var pic = new Image(); pic.src = d.url; pic.alt = ''; pic.draggable = false; pic.setAttribute('data-avatar', '');
        pic.className = 'member-thumb vault-avatar vault-avatar--lg';
        old.replaceWith(pic);
        av.close();
        saveBtn.innerHTML = saveHtml; saveBtn.disabled = false;
        document.querySelectorAll('[data-avatar-edit]:not(.vault-head__edit)').forEach(function (b) { b.remove(); });
      })
      .catch(function (e) { showErr(e.message); saveBtn.innerHTML = saveHtml; saveBtn.disabled = false; });
  });
  var del = av.querySelector('[data-avatar-delete]');
  if (del) del.addEventListener('click', function () {
    post({ action: 'delete' }).then(function () { location.reload(); }).catch(function (e) { showErr(e.message); });
  });

  // Arrivée depuis « Définir la photo de profil » (création du dossier) : recadrage ouvert directement
  if (location.hash === '#photo' && !<?= json_encode((bool) $client['avatar_path']) ?>) openAvatar();
})();
</script>

<?php dash_footer(); ?>
