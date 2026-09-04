<?php
return [
    'session_name' => 'MLS_SESSION',
    'csrf_key' => '_csrf_token',
    'password_algo' => PASSWORD_DEFAULT,
    // Reversible-encryption secret for App\Core\Encryption (Collexia EnDO
    // credential/signature storage, etc.) -- like database.php's real
    // credentials, the actual value belongs only in a local, uncommitted
    // change on each environment, never in git. Generate one with:
    // php -r "echo bin2hex(random_bytes(32));"
    'encryption_key' => 'CHANGE_ME_GENERATE_A_RANDOM_SECRET',
];
