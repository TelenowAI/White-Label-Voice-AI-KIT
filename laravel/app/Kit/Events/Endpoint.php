<?php

namespace App\Kit\Events;

use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;

/**
 * The kit's ONE events endpoint at Telenow (SELF_HOSTED_KIT_PLAN.md §5 C4, §3.8 e): registered on the operator's org
 * with the operator's key, org-wide — so Telenow copies every customer's events to it, stamped `subAccountId` — for
 * exactly the events the kit has handlers for (config('kit.events.handlers')), labelled config('kit.events.source').
 *
 * ensure() keeps it alive: Telenow stops sending to an endpoint for good (a 410, a URL that fails its check) and has no
 * way back, and a disabled one drops out of its list. So when the kit's endpoint is not listed any more, or the kit's
 * URL or events changed, a new one is registered (with a new secret) and a stale one still listed is deleted. The
 * secret before the new one stays valid too, for deliveries signed with it that are still on their way. Calls whose
 * events were lost while no endpoint worked still arrive, through the calls feed (App\Kit\Calls\Calls::feed).
 *
 * Settings: `telenow.events` (id, url, events, registeredAt), `telenow.events_secrets` (encrypted: current, previous),
 * `telenow.events_error` (why the last try failed, for the admin; null when fine), `telenow.events_checked_at`.
 *
 * Not here: receiving and applying events (Inbox).
 */
final class Endpoint
{
    private const STATE = 'telenow.events';

    private const SECRETS = 'telenow.events_secrets';

    private const ERROR = 'telenow.events_error';

    private const CHECKED = 'telenow.events_checked_at';

    public function __construct(private readonly Settings $settings, private readonly TelenowClient $telenow) {}

    /** @return list<string> the secrets a delivery may be signed with: the current one, then the one before */
    public function secrets(): array
    {
        $s = (array) $this->settings->get(self::SECRETS, []);

        return array_values(array_filter([$s['current'] ?? null, $s['previous'] ?? null], fn ($v) => is_string($v) && $v !== ''));
    }

    /** Where Telenow sends the events: config('kit.events.url'), else this kit's own address. */
    public function url(): string
    {
        return config('kit.events.url') ?: rtrim((string) config('app.url'), '/').'/api/kit/telenow/events';
    }

    /**
     * Whether ensure() is due: on every run until an endpoint works (no events arrive without one), then once every
     * config('kit.events.check_minutes').
     */
    public function due(): bool
    {
        if ($this->settings->get(self::ERROR) !== null || ! isset(((array) $this->settings->get(self::STATE, []))['id'])) {
            return true;
        }
        $at = $this->settings->get(self::CHECKED);

        return ! is_string($at) || now()->diffInMinutes($at, absolute: true) >= config('kit.events.check_minutes');
    }

    /** Make sure Telenow sends this kit its events. Answers null when it does, else why not (kept for the admin too). */
    public function ensure(): ?string
    {
        $key = $this->settings->get('telenow.api_key');
        if (! is_string($key) || $key === '') {
            return null; // not set up yet: the wizard comes first
        }
        $this->settings->put(self::CHECKED, now()->toIso8601String());
        $url = $this->url();
        $events = array_keys(config('kit.events.handlers'));
        $state = (array) $this->settings->get(self::STATE, []);
        try {
            $listed = isset($state['id'])
                && in_array($state['id'], array_column($this->telenow->hooks($key, config('kit.events.source')), 'id'), true);
            if ($listed && ($state['url'] ?? null) === $url && ($state['events'] ?? null) === $events) {
                $this->settings->put(self::ERROR, null);

                return null;
            }
            $made = $this->telenow->createHook($key, ['target_url' => $url, 'events' => $events, 'source' => config('kit.events.source')]);
            $secret = $made['signing_secret'] ?? $made['secret'] ?? null;
            if (! is_string($made['id'] ?? null) || ! is_string($secret)) {
                throw new TelenowError(0, __('events.no_secret'));
            }
            $before = (array) $this->settings->get(self::SECRETS, []);
            $this->settings->put(self::SECRETS, ['current' => $secret, 'previous' => $before['current'] ?? null], secret: true);
            $this->settings->put(self::STATE, ['id' => $made['id'], 'url' => $url, 'events' => $events, 'registeredAt' => now()->toIso8601String()]);
            if ($listed) {
                $this->telenow->deleteHook($key, $state['id']); // the old one would keep sending to the old URL or events
            }
            $this->settings->put(self::ERROR, null);

            return null;
        } catch (TelenowError $e) {
            $this->settings->put(self::ERROR, $e->getMessage());

            return $e->getMessage();
        }
    }
}
