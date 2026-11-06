<?php

namespace App\Kit\Voices;

use App\Kit\Settings\Settings;

/**
 * The operator's say on customers cloning voices (Admin → Catalog): none until allowed, and at most so many clones an account (config('kit.own_voices.clones_per_account') until the
 * operator sets another), never more than Telenow's own limit for clones on its keys. Switching it off stops new voices and new clones; voices already made keep working on their
 * agents, and can always be removed or deleted.
 *
 * Read by App\Kit\Voices\OwnVoices; saved by Admin\CatalogController. Not here: the voices themselves (OwnVoices).
 */
final class Cloning
{
    public const ALLOWED = 'own_voices.allowed';

    public const PER_ACCOUNT = 'own_voices.clones_per_account';

    public function __construct(private readonly Settings $settings) {}

    public function allowed(): bool
    {
        return $this->settings->get(self::ALLOWED, false) === true;
    }

    public function allow(bool $allowed): void
    {
        $this->settings->put(self::ALLOWED, $allowed);
    }

    /** Clones one account may have at once. */
    public function perAccount(): int
    {
        $set = $this->settings->get(self::PER_ACCOUNT);

        return min(is_int($set) ? $set : (int) config('kit.own_voices.clones_per_account'), self::most());
    }

    public function setPerAccount(int $clones): void
    {
        $this->settings->put(self::PER_ACCOUNT, max(1, min($clones, self::most())));
    }

    /** Telenow's limit for one account's clones on its keys. */
    public static function most(): int
    {
        return (int) config('kit.own_voices.platform_clone_limit');
    }
}
