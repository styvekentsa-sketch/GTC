<?php
/**
 * Icônes des réseaux sociaux (adresses définies dans config.php → GTC_SOCIAL).
 * Usage : <?= social_links(['facebook', 'linkedin'], 'social--nav') ?>
 */

/** Réseaux pris en charge : libellé + icône SVG (logos simplifiés, couleur héritée). */
const SOCIAL_NETWORKS = [
    'facebook' => [
        'label' => 'Facebook',
        'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M9.1 23.7v-8H6.6v-3.7h2.5v-1.6c0-4.1 1.8-6 5.9-6 .8 0 2.1.2 2.6.3v3.3l-1.4-.1c-2 0-2.8.8-2.8 2.7v1.4h4l-.7 3.7h-3.3v8.2C19.4 23.2 24 18.2 24 12 24 5.4 18.6 0 12 0S0 5.4 0 12c0 5.6 3.9 10.4 9.1 11.7Z"/></svg>',
    ],
    'linkedin' => [
        'label' => 'LinkedIn',
        'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z"/></svg>',
    ],
    'instagram' => [
        'label' => 'Instagram',
        'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5.5"/><circle cx="12" cy="12" r="4.2"/><circle cx="17.6" cy="6.4" r=".6" fill="currentColor"/></svg>',
    ],
    'youtube' => [
        'label' => 'YouTube',
        'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19C0 8.07 0 12 0 12s0 3.93.5 5.81a3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14C24 15.93 24 12 24 12s0-3.93-.5-5.81ZM9.55 15.57V8.43L15.82 12l-6.27 3.57Z"/></svg>',
    ],
    'twitter' => [
        'label' => 'X (Twitter)',
        'svg'   => '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M18.9 1.15h3.68l-8.04 9.19L24 22.85h-7.41l-5.8-7.59-6.64 7.59H.47l8.6-9.83L0 1.15h7.59l5.24 6.93 6.07-6.93Zm-1.29 19.5h2.04L6.49 3.24H4.3l13.31 17.41Z"/></svg>',
    ],
];

/** Réseaux demandés dont l'adresse est renseignée et valide (http/https) : [clé => adresse]. */
function social_available(array $networks): array
{
    $urls = defined('GTC_SOCIAL') ? GTC_SOCIAL : [];
    $list = [];
    foreach ($networks as $key) {
        $url = trim((string) ($urls[$key] ?? ''));
        if (isset(SOCIAL_NETWORKS[$key]) && filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) {
            $list[$key] = $url;
        }
    }
    return $list;
}

/**
 * Liste d'icônes sociales : liens externes ouverts dans un nouvel onglet (rel="noopener noreferrer").
 * Un réseau sans adresse n'est pas affiché ; aucune adresse → chaîne vide.
 */
function social_links(array $networks, string $modifier = ''): string
{
    $available = social_available($networks);
    if (!$available) {
        return '';
    }
    $html = '<ul class="social' . ($modifier !== '' ? ' ' . htmlspecialchars($modifier, ENT_QUOTES) : '') . '">';
    foreach ($available as $key => $url) {
        $label = SOCIAL_NETWORKS[$key]['label'];
        $html .= '<li><a class="social__link social__link--' . $key . '" href="' . htmlspecialchars($url, ENT_QUOTES)
               . '" target="_blank" rel="noopener noreferrer" aria-label="Suivez-nous sur ' . htmlspecialchars($label, ENT_QUOTES)
               . ' (s\'ouvre dans un nouvel onglet)" title="' . htmlspecialchars($label, ENT_QUOTES) . '">'
               . SOCIAL_NETWORKS[$key]['svg'] . '</a></li>';
    }
    return $html . '</ul>';
}
