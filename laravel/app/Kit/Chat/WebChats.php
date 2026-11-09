<?php

namespace App\Kit\Chat;

use App\Kit\Pricing\Pricing;
use App\Kit\Pricing\PricingEngine;
use App\Kit\Pricing\PricingRefused;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\AgentWebChat;
use App\Models\PriceList;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An agent's chat box for the customer's own website (Console → agent → Web chat; contract/kit-api.yaml getAgentWebChat, updateAgentWebChat),
 * and the operator's yes to offering them at all (Admin → Catalog, allowed()). A chat box answers visitors through this platform
 * (App\Kit\Chat\Conversations), which passes each message to the voice platform with the account's own key: the code a customer puts on their
 * pages loads this platform's own script, so nothing of the voice platform reaches a visitor.
 *
 * A chat box answers only while the operator offers web chat, the customer has turned it on with at least one website, and its agent takes calls
 * and is on the voice platform (working()); and, as each message is sent, while the account is not paused and — while customers are charged — its
 * plan prices a chat, which Telenow rates as a call with the channel `chat` (recorded, contract_k15chat.py; config('kit.chat.rated_as')).
 *
 * Not here: conversations (Conversations), the script (public/chat/widget.js), the pages (AgentWebChatController; console agents/AgentWebChat.tsx).
 */
final class WebChats
{
    /** The operator's yes to customers' chat boxes: none answers until it is given, and none once it is taken back. */
    public const ALLOWED = 'chat.allowed';

    /** A cost to ask a plan about: only whether one of its rules prices a chat matters, never the amount. */
    private const SAMPLE_USD = '0.01';

    private const SAMPLE_SECONDS = 60;

    public function __construct(private readonly Settings $settings, private readonly Pricing $pricing, private readonly PricingEngine $engine) {}

    public function allowed(): bool
    {
        return $this->settings->get(self::ALLOWED, false) === true;
    }

    public function allow(bool $allowed): void
    {
        $this->settings->put(self::ALLOWED, $allowed);
    }

    public function of(Agent $agent): ?AgentWebChat
    {
        return AgentWebChat::query()->where('agent_id', $agent->id)->first();
    }

    /** @return array{allowed: bool, priced: bool, enabled: bool, websites: list<string>, title: ?string, greeting: ?string, snippet: ?string} the page's chat box (WebChat) */
    public function present(Agent $agent): array
    {
        $box = $this->of($agent);

        return [
            'allowed' => $this->allowed(),
            'priced' => $this->priced($agent->workspace),
            'enabled' => (bool) $box?->enabled,
            'websites' => array_values((array) ($box?->websites ?? [])),
            'title' => $box?->title,
            'greeting' => $box?->greeting,
            'snippet' => $box?->public_key === null ? null : $this->snippet($box),
        ];
    }

    /** Save the chat box as `$input` (WebChatInput) says. Turning it on needs the operator's yes, a plan that prices a chat, and the agent on the voice platform. */
    public function update(Agent $agent, array $input): array
    {
        $values = Validator::make($input, [
            'enabled' => ['required', 'boolean'],
            'websites' => ['present', 'array', 'max:'.config('kit.chat.websites_max')],
            'websites.*' => ['string', 'max:255'],
            'title' => ['nullable', 'string', 'max:'.config('kit.chat.title_max_length')],
            'greeting' => ['nullable', 'string', 'max:'.config('kit.chat.greeting_max_length')],
        ], [], __('chat.fields'))->validate();
        $websites = [];
        $errors = [];
        foreach (array_values($values['websites']) as $i => $given) {
            $site = self::website((string) $given);
            if ($site === null) {
                $errors["websites.{$i}"] = [__('chat.website_invalid')];
            } else {
                $websites[$site] = true;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $enabled = (bool) $values['enabled'];
        if ($enabled) {
            if ($websites === []) {
                throw ValidationException::withMessages(['websites' => [__('chat.websites_required')]]);
            }
            if (! $this->allowed()) {
                throw new ChatRefused(409, __('chat.not_allowed'));
            }
            if (! $this->priced($agent->workspace)) {
                throw new ChatRefused(409, __('chat.not_priced'));
            }
            if ($agent->telenow_id === null) {
                throw new ChatRefused(409, __('chat.agent_not_ready'));
            }
        }
        $box = $this->of($agent) ?? (new AgentWebChat)->forceFill(['agent_id' => $agent->id, 'workspace_id' => $agent->workspace_id]);
        $box->forceFill([
            'enabled' => $enabled,
            'websites' => array_keys($websites),
            'title' => self::words($values['title'] ?? null),
            'greeting' => self::words($values['greeting'] ?? null),
        ]);
        if ($enabled && $box->public_key === null) {
            $box->public_key = Str::random(config('kit.chat.key_length'));
        }
        $box->save();

        return $this->present($agent);
    }

    /** Whether `$box` answers visitors now, as far as its settings go: the account's pause and plan are asked as each message is sent (Conversations). */
    public function working(AgentWebChat $box): bool
    {
        $agent = $box->agent;

        return $box->enabled && $box->public_key !== null && $this->allowed()
            && $agent !== null && $agent->deleted_at === null && $agent->is_active && $agent->telenow_id !== null;
    }

    /** Whether the account's plan prices a chat. While customers are not charged, nothing is priced and every chat box may answer. */
    public function priced(Workspace $workspace): bool
    {
        if (! $this->pricing->inForce()) {
            return true;
        }
        try {
            $this->pricing->price($workspace, $this->sample());

            return true;
        } catch (PricingRefused) {
            return false;
        }
    }

    /** @return list<array{id: string, name: string}> the price lists with no price for a chat (none while customers are not charged): on Admin → Catalog beside the switch */
    public function unpricedPlans(): array
    {
        if (! $this->pricing->inForce()) {
            return [];
        }
        $lists = $this->pricing->lists();
        $unpriced = [];
        foreach (PriceList::query()->orderBy('id')->get(['id', 'name']) as $plan) {
            try {
                $this->engine->price($lists, $plan->id, $this->pricing->settings()['usdRate'], $this->sample());
            } catch (PricingRefused) {
                $unpriced[] = ['id' => $plan->id, 'name' => $plan->name];
            }
        }

        return $unpriced;
    }

    /** The code a customer puts on each page: this platform's own script, with the chat box's public key. */
    public function snippet(AgentWebChat $box): string
    {
        return '<script src="'.e(url(config('kit.chat.script_path'))).'" data-key="'.e((string) $box->public_key).'" async></script>';
    }

    /**
     * A website as the kit keeps it, and as a browser names it in `Origin`: scheme, host and a port other than the scheme's own, lowercased, a
     * name in another script as its ASCII form. Null when `$given` is not one: https only, or plain http on config('kit.chat.test_hosts'); no path
     * (but a bare `/`), query, fragment or user.
     */
    public static function website(string $given): ?string
    {
        $parts = parse_url(trim($given));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $test = in_array($host, config('kit.chat.test_hosts'), true);
        if (! ($scheme === 'https' || ($scheme === 'http' && $test))) {
            return null;
        }
        if (! $test) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : $host;
            if (! is_string($ascii) || ! str_contains($ascii, '.') || filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || filter_var($ascii, FILTER_VALIDATE_IP) !== false) {
                return null;
            }
            $host = $ascii;
        }
        $port = $parts['port'] ?? null;
        $port = $port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':'.$port;

        return "{$scheme}://{$host}{$port}";
    }

    private static function words(mixed $text): ?string
    {
        $text = is_string($text) ? trim($text) : '';

        return $text === '' ? null : $text;
    }

    /** A chat as a plan is asked about it: rated as Telenow rates one, with each part it charges. */
    private function sample(): array
    {
        $parts = array_fill_keys(config('kit.chat.rated_parts'), self::SAMPLE_USD);
        $total = BigDecimal::of(self::SAMPLE_USD)->multipliedBy(count($parts));

        return ['kind' => 'call', 'attributes' => config('kit.chat.rated_as'), 'durationSec' => self::SAMPLE_SECONDS, 'components' => $parts, 'totalUsd' => (string) $total];
    }
}
