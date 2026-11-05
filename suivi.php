<?php
/**
 * Suivi de colis et de conteneurs — page publique de Global Trading Corporation.
 *
 *   suivi.php                      formulaire de recherche
 *   suivi.php?code=GTC-7K4P-Q9XM   état d'avancement ; le numéro du transporteur (B/L, LTA, n° de conteneur) est
 *                                  aussi accepté
 *
 * Affichage : statut actuel mis en avant (grande icône, progression « étape 2 sur 6 »), barre des 6 étapes du trajet,
 * détails de l'envoi (poids, volume, colis, mode, lieu de retrait), puis historique détaillé de chaque événement
 * (date, heure, lieu, libellé, message de l'agence), du plus récent au plus ancien.
 *
 * Données saisies par l'équipe dans le Dashboard (dashboard/shipments.php). Règles et confidentialité :
 * includes/shipments.php (nom abrégé, téléphone jamais affiché, recherches infructueuses limitées par adresse IP).
 * Résultats non indexés par les moteurs de recherche (noindex dès qu'un code est saisi).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/media.php';   // local_picture() : image de la bannière
require_once __DIR__ . '/includes/db.php';

$code     = trim(mb_substr((string) ($_GET['code'] ?? ''), 0, 60));
$shipment = null;
$events   = [];
$error    = '';

if ($code !== '') {
    if (!db_try()) {
        $error = 'Le suivi est momentanément indisponible. Merci de réessayer dans quelques minutes ou de nous contacter.';
    } else {
        require_once __DIR__ . '/includes/shipments.php';
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
        if (tracking_throttled($ip)) {
            $error = 'Trop de recherches infructueuses depuis votre connexion. Merci de patienter quelques minutes, ou contactez-nous.';
        } elseif (!($shipment = shipment_lookup($code))) {
            tracking_record_miss($ip);
            $error = 'Aucune marchandise ne correspond à « ' . $code . ' ». Vérifiez le code (ex. GTC-7K4P-Q9XM) ou le numéro de B/L.';
        } else {
            $events = shipment_events((int) $shipment['id']);
        }
    }
}

/** Date lisible : « 12 nov. 2026 à 14:30 » (sans heure : « 12 nov. 2026 »). */
function track_date(?string $value, bool $withTime = true): string
{
    if (!$value || !($ts = strtotime($value))) {
        return '';
    }
    $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts) . ($withTime ? ' à ' . date('H:i', $ts) : '');
}

// ---------- Méta & données de la page ----------
$pageTitle       = 'Suivi de colis';
$seoTitle        = 'Suivi de colis et de conteneurs à Douala | GTC';
$pageDescription = 'Suivez votre colis ou votre conteneur confié à Global Trading Corporation : embarquement, transit maritime ou aérien, arrivée à Douala, douane et retrait en agence.';
$currentPage     = 'suivi';
if ($code !== '') {
    $robots = 'noindex, nofollow';   // page de résultat propre à un client
}

include __DIR__ . '/includes/header.php';

$hero = [
    'crumb'   => 'Suivi de colis',
    'picture' => ['name' => 'port-nuit', 'width' => 1600, 'height' => 1067],   // assets/img/hero/port-nuit-*.webp|jpg (port de nuit, fond sombre)
    'overlay' => 'light',
    'eyebrow' => 'Transit & fret international',
    'title'   => 'Suivez votre marchandise',
    'lead'    => "Saisissez le code de suivi remis par notre agence (ou votre numéro de B/L, de LTA ou de conteneur) pour connaître l'étape en cours.",
];
include __DIR__ . '/includes/hero.php';
?>

<section class="section section--white section--tight">
    <div class="container container--narrow">

        <!-- ==================== RECHERCHE ==================== -->
        <form method="get" action="suivi.php" class="form-panel track-search" role="search">
            <label for="code" class="form-label">Code de suivi ou n° de B/L / conteneur</label>
            <div class="track-search__row">
                <input id="code" name="code" type="text" class="form-control<?= $error && $shipment === null && $code !== '' ? ' is-invalid' : '' ?>"
                       value="<?= e($code) ?>" placeholder="Ex. GTC-7K4P-Q9XM" required maxlength="60"
                       autocomplete="off" autocapitalize="characters" spellcheck="false"<?= $code === '' ? ' autofocus' : '' ?>
                       <?= $error ? 'aria-describedby="track-error"' : '' ?>>
                <button type="submit" class="btn btn--primary"><?= icon('search') ?> Suivre</button>
            </div>
            <?php if ($error): ?>
                <p class="form-error track-search__error" id="track-error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>
        </form>

        <?php if ($shipment):
            $mode       = $shipment['transport_mode'];
            $status     = $shipment['status'];
            $timeline   = shipment_timeline($shipment, $events);
            [$step, $steps] = shipment_progress($status);
            $finished   = in_array($status, ['available', 'delivered'], true);
            $measures   = shipment_measures_text($shipment);
            $route      = array_filter([$shipment['origin'], $shipment['destination']]);
            $pickup     = (string) $shipment['pickup_location'];
            ?>
            <!-- ==================== RÉSULTAT ==================== -->
            <article class="track-result" aria-labelledby="track-title">

                <!-- Statut actuel mis en avant -->
                <header class="track-status track-status--<?= $finished ? 'ready' : 'moving' ?>">
                    <span class="track-status__icon" aria-hidden="true"><?= icon(SHIPMENT_STATUSES[$status]['icon'] ?? 'box') ?></span>
                    <div class="track-status__body">
                        <p class="track-status__label">Statut actuel · dossier <?= e($shipment['tracking_number']) ?></p>
                        <h2 class="track-status__title" id="track-title"><?= e(shipment_status_label($status, $mode)) ?></h2>
                        <p class="track-status__text"><?= e(SHIPMENT_STATUSES[$status]['text'] ?? '') ?></p>
                        <div class="track-progress" role="progressbar" aria-valuemin="1" aria-valuemax="<?= $steps ?>" aria-valuenow="<?= $step ?>"
                             aria-valuetext="Étape <?= $step ?> sur <?= $steps ?>">
                            <span class="track-progress__bar" style="width: <?= round($step / $steps * 100) ?>%"></span>
                        </div>
                        <p class="track-status__meta">Étape <?= $step ?> sur <?= $steps ?> · mis à jour le <?= e(track_date($shipment['updated_at'])) ?></p>
                    </div>
                </header>

                <?php if ($status === 'available' && $pickup !== ''): ?>
                    <p class="track-pickup-alert"><?= icon('store') ?>
                        <span><strong>Votre marchandise vous attend.</strong> Lieu de retrait : <?= e($pickup) ?>. Munissez-vous d'une pièce d'identité et de votre code de suivi.</span></p>
                <?php endif; ?>

                <!-- Les 6 étapes du trajet -->
                <ol class="track-steps-bar" aria-label="Étapes du trajet">
                    <?php foreach ($timeline as $st): ?>
                        <li class="track-steps-bar__item is-<?= e($st['state']) ?>"<?= $st['state'] === 'current' ? ' aria-current="step"' : '' ?>>
                            <span class="track-steps-bar__dot" aria-hidden="true"><?= icon($st['state'] === 'done' ? 'check' : $st['icon']) ?></span>
                            <span class="track-steps-bar__label"><?= e($st['label']) ?></span>
                            <span class="visually-hidden"> — <?= ['done' => 'étape franchie', 'current' => 'étape en cours', 'todo' => 'étape à venir'][$st['state']] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <!-- Détails de l'envoi -->
                <h3 class="track-result__subtitle">Détails de l'envoi</h3>
                <dl class="track-facts">
                    <div><dt>Client</dt><dd><?= e(shipment_public_name($shipment['client_name'])) ?></dd></div>
                    <div><dt>Mode de transport</dt><dd><?= e(SHIPMENT_MODES[$mode] ?? $mode) ?></dd></div>
                    <div class="track-facts__wide"><dt>Marchandise</dt><dd><?= e($shipment['description']) ?></dd></div>
                    <?php foreach (['Poids' => 'Poids', 'Volume' => 'Volume', 'Colis' => 'Nombre de colis'] as $key => $label): ?>
                        <?php if (isset($measures[$key])): ?><div><dt><?= e($label) ?></dt><dd><?= e($measures[$key]) ?></dd></div><?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($route): ?><div><dt>Trajet</dt><dd><?= e(implode(' → ', $route)) ?></dd></div><?php endif; ?>
                    <?php if ($shipment['carrier_reference']): ?><div><dt>N° transporteur</dt><dd><?= e($shipment['carrier_reference']) ?></dd></div><?php endif; ?>
                    <?php if ($shipment['eta'] && !$finished): ?><div><dt>Arrivée prévue</dt><dd><?= e(track_date($shipment['eta'], false)) ?></dd></div><?php endif; ?>
                    <?php if ($pickup !== ''): ?>
                        <div class="track-facts__wide track-facts__pickup"><dt>Lieu de retrait à Douala</dt><dd>
                            <?= e($pickup) ?>
                            <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= e(rawurlencode($pickup . ', Douala, Cameroun')) ?>" target="_blank" rel="noopener">Voir sur la carte <?= icon('external') ?></a>
                        </dd></div>
                    <?php endif; ?>
                </dl>

                <!-- Historique détaillé : chaque événement, du plus récent au plus ancien -->
                <h3 class="track-result__subtitle">Historique détaillé <small>(<?= count($events) ?> événement<?= count($events) > 1 ? 's' : '' ?>)</small></h3>
                <ol class="timeline">
                    <?php foreach (array_reverse($events) as $i => $ev):
                        $ts = strtotime($ev['event_date']); ?>
                        <li class="timeline__step <?= $i === 0 ? 'is-current' : 'is-done' ?>">
                            <span class="timeline__dot" aria-hidden="true"><?= icon(SHIPMENT_STATUSES[$ev['status']]['icon'] ?? 'box') ?></span>
                            <div class="timeline__body">
                                <p class="timeline__when"><time datetime="<?= e(date('c', $ts)) ?>"><?= e(track_date($ev['event_date'], false)) ?> <span>à <?= e(date('H:i', $ts)) ?></span></time></p>
                                <p class="timeline__label"><?= e(shipment_event_title($ev, $mode)) ?></p>
                                <p class="timeline__meta">
                                    <?php if ($ev['location']): ?><span class="timeline__place"><?= icon('pin') ?> <?= e($ev['location']) ?></span><?php endif; ?>
                                    <?php if ((string) $ev['label'] !== ''): ?><span class="timeline__chip"><?= e(shipment_status_label($ev['status'], $mode)) ?></span><?php endif; ?>
                                </p>
                                <?php if ($ev['note']): ?><p class="timeline__note"><?= nl2br(e($ev['note'])) ?></p><?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </article>
        <?php endif; ?>

        <!-- ==================== AIDE ==================== -->
        <aside class="track-help">
            <span class="track-help__icon" aria-hidden="true"><?= icon('phone') ?></span>
            <div>
                <p class="track-help__title">Une question sur votre marchandise ?</p>
                <p>Notre équipe transit vous répond au <a href="tel:<?= e(GTC_PHONE_LINK) ?>"><?= e(GTC_PHONE) ?></a>
                    ou sur <a href="https://wa.me/<?= e(GTC_WHATSAPP) ?>?text=<?= e(rawurlencode('Bonjour GTC, je souhaite des nouvelles de ma marchandise' . ($shipment ? ' (dossier ' . $shipment['tracking_number'] . ')' : '') . '.')) ?>" target="_blank" rel="noopener">WhatsApp</a>.
                    Munissez-vous de votre code de suivi.</p>
                <p class="track-help__more">Pas encore expédié ? <a href="calculateur-cbm.php">Calculez le volume (CBM) et estimez le fret</a> de vos colis.</p>
            </div>
        </aside>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
