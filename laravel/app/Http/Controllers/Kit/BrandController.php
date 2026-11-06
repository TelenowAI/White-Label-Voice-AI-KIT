<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Brand\Brand;
use App\Kit\Captcha\Captcha;
use App\Kit\Email\Emails;
use App\Kit\Oidc\OidcProviders;
use App\Kit\Signup\SignupSettings;
use App\Kit\Sms\Sms;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/kit/brand (contract/kit-api.yaml `getBrand`): the operator's brand for theming the console and
 * the website. Public: the console applies it before anyone signs in.
 *
 * The answer is what the operator saved (the wizard's brand step) over config/kit.php's defaults (App\Kit\Brand),
 * and what sign-in pages offer: "Forgot your password?" (when the kit can send email), signing up (when it is open),
 * an emailed sign-in link, and a sign-in code texted to a phone (each when the operator has that way in on and the kit can
 * send it); the OpenID Connect providers customers may sign in with; and the bot check the public forms show (its widget
 * script and site key, and the forms it guards).
 * Not here: editing the brand (BrandStep, later admin), or the website theme.
 */
class BrandController
{
    public function __invoke(Brand $brand, Emails $emails, SignupSettings $signup, Sms $sms, Captcha $captcha, OidcProviders $providers): JsonResponse
    {
        $current = $brand->current();

        return response()->json([
            'name' => $current['name'],
            'logoUrl' => $current['logoUrl'],
            'supportEmail' => $current['supportEmail'],
            'colors' => (object) $current['colors'],
            'passwordReset' => $emails->on(),
            'signUp' => $signup->open(),
            'signInPassword' => $signup->uses('password'),
            'signInLink' => $emails->on() && $signup->uses('magic_link'),
            'signInEmailCode' => $emails->on() && $signup->uses('email_code'),
            'signInCode' => $sms->on() && $signup->uses('phone_code'),
            'signInCodeChannel' => $sms->channel(),
            'signInWith' => array_map(fn (array $p) => ['id' => $p['id'], 'name' => $p['name']], $providers->enabled()),
            'captcha' => $captcha->forBrowser(),
        ]);
    }
}
