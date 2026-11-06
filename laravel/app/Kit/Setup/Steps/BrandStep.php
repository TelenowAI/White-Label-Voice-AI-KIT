<?php

namespace App\Kit\Setup\Steps;

use App\Kit\Brand\Brand;
use App\Kit\Settings\Settings;
use App\Kit\Setup\Field;
use App\Kit\Setup\SetupStep;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * Wizard step 5: the brand (SELF_HOSTED_KIT_PLAN.md §4.1.4): the platform's name, logo address, support email and
 * a colour for every role the brand has (config/kit.php's roles, plus any the operator saved). Colours are hex
 * values only, so nothing but a colour reaches the console's CSS variables.
 *
 * Not here: uploading a logo or favicon (media storage, K1), legal details (K1), the website theme.
 */
final class BrandStep extends SetupStep
{
    private const HEX = '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    public function __construct(private readonly Settings $settings, private readonly Brand $brand) {}

    public function id(): string
    {
        return 'brand';
    }

    public function done(): bool
    {
        return $this->settings->has('brand.saved_at');
    }

    public function fields(Request $request, bool $mayRead): array
    {
        $brand = $this->brand->current();
        $fields = [
            new Field('name', $this->text('fields.name'), 'text', true, $brand['name']),
            new Field('logoUrl', $this->text('fields.logoUrl'), 'url', false, $brand['logoUrl']),
            new Field('supportEmail', $this->text('fields.supportEmail'), 'email', false, $brand['supportEmail']),
        ];
        foreach ($brand['colors'] as $role => $value) {
            $fields[] = new Field("colors.{$role}", $this->text('fields.color', ['role' => $role]), 'color', true, $value);
        }

        return $fields;
    }

    public function submit(Request $request, array $input): void
    {
        $input = Arr::undot($input);
        $roles = array_keys($this->brand->current()['colors']);
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'logoUrl' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'supportEmail' => ['nullable', 'string', 'email', 'max:255'],
            'colors' => ['required', 'array:'.implode(',', $roles)],
        ];
        $names = ['name' => $this->text('fields.name'), 'logoUrl' => $this->text('fields.logoUrl'), 'supportEmail' => $this->text('fields.supportEmail')];
        foreach ($roles as $role) {
            $rules["colors.{$role}"] = ['required', 'string', 'regex:'.self::HEX];
            $names["colors.{$role}"] = $this->text('fields.color', ['role' => $role]);
        }
        $values = Validator::make($input, $rules, [], $names)->validate();

        $this->settings->put('brand.name', $values['name']);
        $this->settings->put('brand.logo_url', ($values['logoUrl'] ?? '') !== '' ? $values['logoUrl'] : null);
        $this->settings->put('brand.support_email', ($values['supportEmail'] ?? '') !== '' ? $values['supportEmail'] : null);
        $this->settings->put('brand.colors', $values['colors']);
        $this->settings->put('brand.saved_at', now()->toIso8601String());
    }
}
