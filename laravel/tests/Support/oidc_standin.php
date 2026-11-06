<?php

/**
 * A stand-in OpenID Connect provider for the end-to-end copy only (OidcTest fakes one with Http::fake instead):
 * `php -S 127.0.0.1:8816 tests/Support/oidc_standin.php`, with
 * - OIDC_ISSUER: its issuer (http://127.0.0.1:8816);
 * - OIDC_CLIENTS: the clients it knows, `id:secret,…`;
 * - OIDC_ACCOUNTS: the people who can sign in, `sub|email|verified(1/0),…`;
 * - OIDC_STATE_DIR: where it keeps its RSA key (made on first use) and its codes.
 * Discovery, a sign-in page (pick a person, or cancel), the token endpoint (checks the client secret, the redirect
 * address and the PKCE verifier; a code works once) answering an RS256 ID token, and its JWKS. No real accounts.
 */
$issuer = rtrim((string) getenv('OIDC_ISSUER'), '/');
$dir = getenv('OIDC_STATE_DIR') ?: sys_get_temp_dir().'/oidc_standin';
@mkdir($dir, 0700, true);
$clients = [];
foreach (array_filter(explode(',', (string) getenv('OIDC_CLIENTS'))) as $pair) {
    [$id, $secret] = explode(':', $pair, 2);
    $clients[$id] = $secret;
}
$accounts = array_map(fn (string $a) => array_combine(['sub', 'email', 'verified'], explode('|', $a, 3)),
    array_filter(explode(',', (string) getenv('OIDC_ACCOUNTS'))));

function b64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function signingKey(string $dir): OpenSSLAsymmetricKey
{
    $file = "{$dir}/key.pem";
    if (! is_file($file)) {
        $made = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($made, $pem);
        file_put_contents($file, $pem);
    }

    return openssl_pkey_get_private((string) file_get_contents($file));
}

function json(int $status, array $body): bool
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);

    return true;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($path === '/.well-known/openid-configuration') {
    return json(200, ['issuer' => $issuer, 'authorization_endpoint' => "{$issuer}/authorize", 'token_endpoint' => "{$issuer}/token",
        'jwks_uri' => "{$issuer}/jwks", 'response_types_supported' => ['code'], 'id_token_signing_alg_values_supported' => ['RS256'],
        'token_endpoint_auth_methods_supported' => ['client_secret_post']]);
}
if ($path === '/jwks') {
    $rsa = openssl_pkey_get_details(signingKey($dir))['rsa'];

    return json(200, ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'standin-1', 'n' => b64($rsa['n']), 'e' => b64($rsa['e'])]]]);
}
if ($path === '/authorize' && $method === 'GET') {
    $q = $_GET;
    if (! isset($clients[$q['client_id'] ?? ''])) {
        http_response_code(400);
        echo 'Unknown client';

        return true;
    }
    header('Content-Type: text/html; charset=utf-8');
    $hidden = '';
    foreach (['client_id', 'redirect_uri', 'state', 'nonce', 'code_challenge'] as $k) {
        $hidden .= '<input type="hidden" name="'.$k.'" value="'.htmlspecialchars((string) ($q[$k] ?? '')).'">';
    }
    $buttons = '';
    foreach ($accounts as $a) {
        $buttons .= '<p><button name="sub" value="'.htmlspecialchars($a['sub']).'">Continue as '.htmlspecialchars($a['email']).'</button></p>';
    }
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stand-in ID</title>'
        .'<body style="font-family:sans-serif;max-width:28rem;margin:2rem auto;padding:0 1rem"><h1>Stand-in ID</h1>'
        .'<form method="post" action="/authorize">'.$hidden.$buttons.'<p><button name="sub" value="">Cancel</button></p></form>';

    return true;
}
if ($path === '/authorize' && $method === 'POST') {
    $p = $_POST;
    $back = $p['redirect_uri'].(str_contains($p['redirect_uri'], '?') ? '&' : '?');
    if (($p['sub'] ?? '') === '') {
        header('Location: '.$back.http_build_query(['error' => 'access_denied', 'state' => $p['state']]), true, 302);

        return true;
    }
    $code = b64(random_bytes(24));
    file_put_contents("{$dir}/code-".hash('sha256', $code), json_encode($p));
    header('Location: '.$back.http_build_query(['code' => $code, 'state' => $p['state']]), true, 302);

    return true;
}
if ($path === '/token' && $method === 'POST') {
    $p = $_POST;
    $file = "{$dir}/code-".hash('sha256', (string) ($p['code'] ?? ''));
    if (! is_file($file)) {
        return json(400, ['error' => 'invalid_grant']);
    }
    $grant = json_decode((string) file_get_contents($file), true);
    unlink($file); // once
    $ok = ($clients[$p['client_id'] ?? ''] ?? null) === ($p['client_secret'] ?? '') && $grant['client_id'] === ($p['client_id'] ?? '')
        && $grant['redirect_uri'] === ($p['redirect_uri'] ?? '')
        && hash_equals($grant['code_challenge'], b64(hash('sha256', (string) ($p['code_verifier'] ?? ''), true)));
    if (! $ok) {
        return json(400, ['error' => 'invalid_grant']);
    }
    $person = current(array_filter($accounts, fn (array $a) => $a['sub'] === $grant['sub']));
    $claims = ['iss' => $issuer, 'aud' => $grant['client_id'], 'sub' => $person['sub'], 'email' => $person['email'],
        'email_verified' => $person['verified'] === '1', 'nonce' => $grant['nonce'], 'iat' => time(), 'exp' => time() + 300];
    $input = b64(json_encode(['alg' => 'RS256', 'kid' => 'standin-1', 'typ' => 'JWT'])).'.'.b64(json_encode($claims));
    openssl_sign($input, $signature, signingKey($dir), OPENSSL_ALGO_SHA256);

    return json(200, ['access_token' => b64(random_bytes(16)), 'token_type' => 'Bearer', 'expires_in' => 300, 'id_token' => $input.'.'.b64($signature)]);
}
http_response_code(404);
echo 'Not found';

return true;
