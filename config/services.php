<?php

/**
 * Third-party service credentials. Follows the same convention as
 * config/database.php: this file is git-tracked with safe local-dev
 * defaults, and the real production values are applied directly on the
 * server as an uncommitted local modification -- never committed to git.
 */
return [
    'turnstile' => [
        // Cloudflare's published "always passes" test keys -- safe for
        // local development. Production overrides these with the real
        // site/secret keys.
        'site_key' => '1x00000000000000000000AA',
        'secret_key' => '1x0000000000000000000000000000000AA',
    ],

    // Collexia JSON REST API (EnDO), "CO JSON REST API Interface
    // Specification V3.0". base_url is left empty on purpose so an
    // unconfigured environment fails loudly (RuntimeException) instead of
    // silently calling a guessed host. Production overrides all of these
    // with the real values supplied by Collexia once the API acceptance
    // with Collexia/Creditinfo's legal review has concluded.
    'collexia' => [
        'base_url' => '',
        'merchant_gid' => null,
        'remote_gid' => null,
        'system_username' => '',
        'front_end_username' => '',
    ],
];
