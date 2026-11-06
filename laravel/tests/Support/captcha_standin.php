<?php

/**
 * A stand-in bot check for the end-to-end copy only (CaptchaTest fakes the provider with Http::fake instead):
 * `CAPTCHA_SECRET=… php -S 127.0.0.1:8817 tests/Support/captcha_standin.php`. `/api.js` defines `window.standincheck`
 * with the same render()/reset() the real widgets have (a checkbox; ticking it calls back with a one-time token);
 * `/siteverify` answers as Turnstile's does — `success` only for the right secret and an unused token. Registered as a
 * provider only in the e2e copy's config/kit.php.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$dir = getenv('CAPTCHA_STATE_DIR') ?: sys_get_temp_dir().'/captcha_standin';
@mkdir($dir, 0700, true);

if ($path === '/api.js') {
    header('Content-Type: application/javascript');
    echo <<<'JS'
window.standincheck = {
  render: function (element, options) {
    var label = document.createElement('label');
    var box = document.createElement('input');
    box.type = 'checkbox';
    box.onchange = function () {
      if (!box.checked) return;
      fetch('http://127.0.0.1:8817/token', { method: 'POST' }).then(function (r) { return r.text(); }).then(function (t) { options.callback(t); });
    };
    label.appendChild(box);
    label.appendChild(document.createTextNode(' I am not a robot (stand-in check)'));
    element.appendChild(label);
    element.__box = box;
    return element.id || 'standin-' + Math.random();
  },
  reset: function () {
    document.querySelectorAll('[data-testid=captcha] input').forEach(function (b) { b.checked = false; });
  }
};
JS;

    return true;
}
if ($path === '/token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Access-Control-Allow-Origin: *');
    $token = 'pass-'.bin2hex(random_bytes(12));
    touch("{$dir}/{$token}");
    echo $token;

    return true;
}
if ($path === '/siteverify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (($_POST['secret'] ?? '') !== getenv('CAPTCHA_SECRET')) {
        echo json_encode(['success' => false, 'error-codes' => ['invalid-input-secret']]);

        return true;
    }
    $token = (string) ($_POST['response'] ?? '');
    $file = "{$dir}/".basename($token);
    $ok = str_starts_with($token, 'pass-') && is_file($file);
    if ($ok) {
        unlink($file); // a token works once
    }
    echo json_encode($ok ? ['success' => true] : ['success' => false, 'error-codes' => ['invalid-input-response']]);

    return true;
}
http_response_code(404);
echo 'Not found';

return true;
