<?php

namespace App\Kit\Campaigns;

use App\Kit\Net\LinkRefused;
use App\Kit\Net\PublicLinks;
use App\Kit\Settings\Settings;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A campaign's people from a link (console → a campaign → Contacts from a link; contract/kit-api.yaml setCampaignSource, removeCampaignSource): a CSV
 * at a web address — a spreadsheet published to the web as CSV, say — read now and again every config('kit.campaigns.link_every_minutes') while the
 * campaign is not finished, each read adding only the numbers the campaign does not have yet (by their digits) and never taking anyone off. Read as a
 * CSV upload is (Campaigns::parse) and added the same way (Campaigns::insert, then the push, which keeps do-not-call numbers out as for any import).
 *
 * The kit's own server reads the link, so only through PublicLinks (public addresses only, pinned, each redirect checked, size and time capped), and
 * only once the operator allows it (Admin → Catalog; off until then): turned off, links stay on their campaigns but are not read. Never Telenow's
 * campaign sources, which connect a spreadsheet through Telenow's own Google sign-in.
 *
 * Not here: the read itself (App\Kit\Net\PublicLinks), parsing and adding people (Campaigns), the schedule (kit:campaign-links).
 */
final class CampaignSources
{
    public const ALLOWED = 'campaigns.links_allowed';

    public function __construct(private readonly Campaigns $campaigns, private readonly PublicLinks $links, private readonly Settings $settings) {}

    public function allowed(): bool
    {
        return $this->settings->get(self::ALLOWED, false) === true;
    }

    public function allow(bool $allowed): void
    {
        $this->settings->put(self::ALLOWED, $allowed);
    }

    /** @return array{added: int, alreadyThere: int, skipped: int, noCountryCode: int} the link set and read now */
    public function set(Campaign $campaign, array $input): array
    {
        if (! $this->allowed()) {
            throw new CampaignRefused(403, __('campaigns.links_off'));
        }
        if (in_array($campaign->status, config('kit.campaigns.finished_statuses'), true)) {
            throw new CampaignRefused(409, __('campaigns.finished'));
        }
        $url = trim((string) Validator::make($input, ['url' => ['required', 'string', 'max:2048']], [], ['url' => __('campaigns.link_field')])->validate()['url']);
        try {
            $read = $this->read($campaign, $url, 'url');
        } catch (LinkRefused $e) {
            throw ValidationException::withMessages(['url' => [self::words($e)]]);
        }
        $campaign->forceFill(['source_url' => $url, 'source_checked_at' => now(), 'source_problem' => null, 'source_added' => $read['added']])->save();

        return $read;
    }

    public function remove(Campaign $campaign): void
    {
        $campaign->forceFill(['source_url' => null, 'source_checked_at' => null, 'source_problem' => null, 'source_added' => 0])->save();
    }

    /** Every link that is due read again (at most config('kit.campaigns.link_per_run') a run); a link that cannot be read says why on its campaign. */
    public function due(): int
    {
        if (! $this->allowed()) {
            return 0;
        }
        $before = now()->subMinutes((int) config('kit.campaigns.link_every_minutes'));
        $read = 0;
        $due = Campaign::query()->whereNotNull('source_url')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', config('kit.campaigns.finished_statuses')))
            // A link is read when it is set, so a followed one always has when it was last read: the index on that time finds only them.
            ->where('source_checked_at', '<=', $before)
            ->orderBy('source_checked_at')->limit((int) config('kit.campaigns.link_per_run'))->get();
        foreach ($due as $campaign) {
            try {
                $got = $this->read($campaign, $campaign->source_url, 'url');
                $campaign->forceFill(['source_checked_at' => now(), 'source_problem' => null, 'source_added' => $campaign->source_added + $got['added']])->save();
            } catch (LinkRefused $e) {
                $campaign->forceFill(['source_checked_at' => now(), 'source_problem' => self::words($e)])->save();
            } catch (ValidationException $e) {
                $campaign->forceFill(['source_checked_at' => now(), 'source_problem' => collect($e->errors())->flatten()->first()])->save();
            }
            $read++;
        }

        return $read;
    }

    /** @return array{added: int, alreadyThere: int, skipped: int, noCountryCode: int} */
    private function read(Campaign $campaign, string $url, string $field): array
    {
        $csv = $this->links->read($url, (int) config('kit.campaigns.import_max_bytes'));
        [$rows, $skipped] = $this->campaigns->parse($csv, $field);
        $digits = fn (string $phone) => preg_replace('/\D+/', '', $phone);
        $have = array_flip(array_map($digits, CampaignTarget::query()->where('campaign_id', $campaign->id)->pluck('phone_number')->all()));
        $new = [];
        $alreadyThere = 0;
        foreach ($rows as $row) {
            $key = $digits($row['phone']);
            if ($key === '' || isset($have[$key])) {
                $alreadyThere++;

                continue;
            }
            $have[$key] = true;
            $new[] = $row;
        }
        $this->campaigns->insert($campaign, $new);

        return ['added' => count($new), 'alreadyThere' => $alreadyThere, 'skipped' => $skipped, 'noCountryCode' => count(array_filter($new, fn (array $r) => ! str_starts_with($r['phone'], '+')))];
    }

    /** Why a link could not be read, in the customer's words. */
    public static function words(LinkRefused $e): string
    {
        return __('links.'.$e->reason, $e->details + ['max' => intdiv((int) config('kit.campaigns.import_max_bytes'), 1024 * 1024)]);
    }
}
