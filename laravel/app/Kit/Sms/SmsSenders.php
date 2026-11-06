<?php

namespace App\Kit\Sms;

use App\Kit\Settings\Settings;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * How the kit sends texts, as the operator set it (SELF_HOSTED_KIT_PLAN.md §4.7): the senders in the order they are
 * tried (the first sends, the next ones are fallbacks), each a driver from config('kit.sms.drivers') with its saved
 * config — the whole list stored encrypted, as it holds API keys.
 *
 * Not here: sending (Sms), changing the list (SmsSetup).
 */
final class SmsSenders
{
    public const LIST = 'sms.senders';

    public function __construct(private readonly Settings $settings) {}

    /** @return list<array{id: string, driver: string, config: array<string, mixed>}> in the order they are tried */
    public function all(): array
    {
        return array_values(array_filter($this->settings->get(self::LIST, []),
            fn ($s) => is_array($s) && is_string($s['driver'] ?? null) && array_key_exists($s['driver'], config('kit.sms.drivers'))));
    }

    /** Whether the kit can send texts. */
    public function on(): bool
    {
        return $this->all() !== [];
    }

    public function driver(string $id): SmsDriver
    {
        $class = config("kit.sms.drivers.{$id}");
        if (! is_string($class)) {
            throw new InvalidArgumentException("Unknown SMS driver {$id}");
        }

        return app($class);
    }

    /** @param  list<array{id: string, driver: string, config: array}>  $senders */
    public function put(array $senders): void
    {
        $this->settings->put(self::LIST, array_values($senders), true);
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

        return array_intersect_key(Validator::make($input, $d->rules(), [], $names)->validate(), $d->rules());
    }
}
