<?php
/**
 * Devises & taux indicatifs — Global Trading Corporation
 *
 * Utilisé par le simulateur et la grille des devises de services.php.
 *
 * Les taux réellement appliqués viennent des cours du marché (includes/exchange_rates.php) ou des
 * Paramètres du Dashboard ; les valeurs 'rate' ci-dessous ne servent qu'en dernier recours.
 *
 * Attention : les taux ci-dessous sont INDICATIFS : ils servent uniquement à donner un ordre
 *   de grandeur au visiteur. Le taux réel est toujours confirmé par WhatsApp.
 *   Mettez à jour 'rate' et GTC_RATES_UPDATED aussi souvent que possible.
 *   (La parité EUR / FCFA est fixe : 1 EUR = 655,957 FCFA.)
 *
 * rate         : valeur de 1 unité de la devise, exprimée en FCFA
 * availability : 'available' (disponible) | 'request' (sur demande) | 'limited' (disponibilité limitée)
 */

const GTC_RATES_UPDATED = '2026-10-15';

const GTC_CURRENCIES = [
    'EUR' => [
        'name'         => 'Euro',
        'region'       => 'Union européenne',
        'symbol'       => '€',
        'flag'         => 'eu',
        'decimals'     => 2,
        'rate'         => 655.957,
        'availability' => 'available',
        'channels'     => ['Guichet', 'Transfert'],
    ],
    'USD' => [
        'name'         => 'Dollar américain',
        'region'       => 'États-Unis',
        'symbol'       => '$',
        'flag'         => 'us',
        'decimals'     => 2,
        'rate'         => 600,
        'availability' => 'available',
        'channels'     => ['Guichet', 'Transfert'],
    ],
    'CAD' => [
        'name'         => 'Dollar canadien',
        'region'       => 'Canada',
        'symbol'       => 'C$',
        'flag'         => 'ca',
        'decimals'     => 2,
        'rate'         => 435,
        'availability' => 'available',
        'channels'     => ['Guichet', 'Transfert'],
    ],
    'FCFA' => [
        'name'         => 'Franc CFA (XAF)',
        'region'       => 'Cameroun · CEMAC',
        'symbol'       => 'F',
        'flag'         => 'cm',
        'decimals'     => 0,
        'rate'         => 1,
        'availability' => 'available',
        'channels'     => ['Guichet', 'Transfert'],
    ],
    'NGN' => [
        'name'         => 'Naira nigérian',
        'region'       => 'Nigeria',
        'symbol'       => '₦',
        'flag'         => 'ng',
        'decimals'     => 2,
        'rate'         => 0.4,
        'availability' => 'available',
        'channels'     => ['Guichet', 'Transfert'],
    ],
    'AED' => [
        'name'         => 'Dirham des Émirats',
        'region'       => 'Dubaï · Émirats arabes unis',
        'symbol'       => 'د.إ',
        'flag'         => 'ae',
        'decimals'     => 2,
        'rate'         => 163,
        'availability' => 'request',
        'channels'     => ['Guichet', 'Sur réservation'],
    ],
    'CNY' => [
        'name'         => 'Yuan chinois',
        'region'       => 'Chine',
        'symbol'       => '¥',
        'flag'         => 'cn',
        'decimals'     => 2,
        'rate'         => 84,
        'availability' => 'limited',
        'channels'     => ['Guichet', 'Sur réservation'],
    ],
];

const GTC_AVAILABILITY = [
    'available' => ['label' => 'Disponible',               'tone' => 'success'],
    'request'   => ['label' => 'Sur demande',              'tone' => 'warning'],
    'limited'   => ['label' => 'Disponibilité limitée',    'tone' => 'muted'],
];
