<?php

namespace App\Kit\Email;

use App\Kit\Brand\Brand;
use App\Kit\Settings\Settings;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * How the kit sends email, as the operator set it (SELF_HOSTED_KIT_PLAN.md §4.7): the senders in the order they are
 * tried (the first sends, the next ones are fallbacks), each a driver from config('kit.email.drivers') with its saved
 * config — the whole list stored encrypted, as it holds passwords; who emails come from; and the site's address,
 * which every link in an email opens (the address the operator confirmed, else APP_URL — never a request's Host, so
 * a forged Host cannot point a reset link elsewhere).
 *
 * Not here: sending (Emails), the forms around it (EmailStep, Admin\EmailController).
 */
final class Senders
{
    public const LIST = 'email.senders';

    public const FROM = 'email.from';

    public const SITE = 'site.url';

    /** The wizard's "not now": the step is done with no sender. */
    public const SKIPPED = 'email.skipped';

    public function __construct(private readonly Settings $settings, private readonly Brand $brand) {}

    /** @return list<array{id: string, driver: string, config: array<string, mixed>}> in the order they are tried */
    public function all(): array
    {
        return array_values(array_filter($this->settings->get(self::LIST, []),
            fn ($s) => is_array($s) && is_string($s['driver'] ?? null) && array_key_exists($s['driver'], config('kit.email.drivers'))));
    }

    /** Whether the kit can send email: a sender and who emails come from. */
    public function on(): bool
    {
        return $this->all() !== [] && $this->from()['address'] !== null;
    }

    public function driver(string $id): EmailDriver
    {
        $class = config("kit.email.drivers.{$id}");
        if (! is_string($class)) {
            throw new InvalidArgumentException("Unknown email driver {$id}");
        }

        return app($class);
    }

    /** @param  list<array{id: string, driver: string, config: array}>  $senders */
    public function put(array $senders): void
    {
        $this->settings->put(self::LIST, array_values($senders), true);
    }

    /** @return array{address: ?string, name: string} the name defaults to the brand's */
    public function from(): array
    {
        $from = $this->settings->get(self::FROM, []);

        return ['address' => is_string($from['address'] ?? null) ? $from['address'] : null,
            'name' => is_string($from['name'] ?? null) && $from['name'] !== '' ? $from['name'] : $this->brand->current()['name']];
    }

    public function putFrom(string $address, ?string $name): void
    {
        $this->settings->put(self::FROM, ['address' => $address, 'name' => $name]);
    }

    public function siteUrl(): string
    {
        $saved = $this->settings->get(self::SITE);

        return rtrim(is_string($saved) && $saved !== '' ? $saved : (string) config('app.url'), '/');
    }

    public function putSiteUrl(string $url): void
    {
        $this->settings->put(self::SITE, rtrim($url, '/'));
    }

    /** A page of the site, for a link in an email. */
    public function link(string $path): string
    {
        return $this->siteUrl().'/'.ltrim($path, '/');
    }

    /**
     * Check one sender's form for a driver (fields named without a prefix). A secret left empty keeps the one in
     * `$saved`. Answers the config to save; throws a ValidationException naming the refused fields.
     */
    public function check(string $driver, array $input, array $saved = []): array
    {
        $d = $this->driver($driver);
        $names = [];
        foreach ($d->fields($saved) as $field) {
            $names[$field->name] = $field->label;
        }
        foreach ($d->secrets() as $secret) {
            if (($input[$secret] ?? '') === '' && ($saved[$secret] ?? '') !== '') {
                $input[$secret] = $saved[$secret];
            }
        }

        return Validator::make($input, $d->rules(), [], $names)->validate();
    }
}
