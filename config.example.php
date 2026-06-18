<?php
/**
 * Launchmind Blog config.
 *
 * Copy this file to your project's config/ directory and rename it to
 * launchmind-blog.php. Craft merges these values over the plugin defaults.
 * Values set here override (and lock) the corresponding settings in the
 * control panel.
 */

return [
    // Your Launchmind API key. An environment variable is recommended.
    'apiKey' => '$LAUNCHMIND_API_KEY',

    // API base URL. Leave as default unless instructed otherwise.
    'apiBase' => 'https://launchmind.io/api',

    // Front-end path: 'blog' → /blog and /blog/<slug>.
    'blogPath' => 'blog',

    // Language to request: '' (auto / site language), or one of
    // en, nl, de, fr, es, it, hi, pl.
    'language' => '',

    // Fresh-cache window in seconds (min 60).
    'cacheTtl' => 600,

    // Server-side page-view analytics beacon.
    'enableTracking' => true,
];
