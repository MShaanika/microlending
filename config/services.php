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
];
