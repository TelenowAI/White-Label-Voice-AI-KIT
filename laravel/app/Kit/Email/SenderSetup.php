<?php

namespace App\Kit\Email;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Changing how the kit sends email — the wizard's Email step and Admin → Email both go through here. A sender is
 * never saved before it has sent one test email to the person saving it (Emails::test), from the saved from address,
 * so a mistyped server or password is caught at once, in the sender's own words. Up to
 * config('kit.email.max_senders'), in the order they are tried.
 *
 * Not here: sending (Emails), the stored values (Senders), the forms (EmailStep, Admin\EmailController).
 */
final class SenderSetup
{
    public function __construct(private readonly Senders $senders, private readonly Emails $emails) {}

    /** Save the site's address, and who emails come from (an empty name: the brand's). */
    public function saveSite(array $input): void
    {
        $values = Validator::make($input, [
            'siteUrl' => ['required', 'string', 'url:http,https', 'max:255'],
            'fromAddress' => ['required', 'string', 'email', 'max:255'],
            'fromName' => ['nullable', 'string', 'max:120'],
        ], [], $this->names())->validate();
        $this->senders->putSiteUrl($values['siteUrl']);
        $this->senders->putFrom($values['fromAddress'], ($values['fromName'] ?? '') !== '' ? $values['fromName'] : null);
    }

    /** Add a sender after the others. Answers it. */
    public function add(array $input, string $testTo): array
    {
        $all = $this->senders->all();
        if (count($all) >= config('kit.email.max_senders')) {
            throw new EmailRefused(409, __('emails.too_many', ['max' => config('kit.email.max_senders')]));
        }
        $driver = Validator::make($input, ['driver' => ['required', 'string', Rule::in(array_keys(config('kit.email.drivers')))]],
            [], $this->names())->validate()['driver'];
        $sender = ['id' => (string) Str::uuid(), 'driver' => $driver, 'config' => $this->checked($driver, $input, [], $testTo)];
        $this->senders->put([...$all, $sender]);

        return $sender;
    }

    /** Change one sender's settings (its driver stays; a secret left empty is kept). Answers it. */
    public function update(string $id, array $input, string $testTo): array
    {
        $all = $this->senders->all();
        $at = $this->at($all, $id);
        $all[$at]['config'] = $this->checked($all[$at]['driver'], $input, $all[$at]['config'], $testTo);
        $this->senders->put($all);

        return $all[$at];
    }

    /** The wizard's sender: the first one, made or changed. */
    public function putFirst(string $driver, array $input, string $testTo): void
    {
        $all = $this->senders->all();
        $first = $all[0] ?? null;
        $same = $first !== null && $first['driver'] === $driver;
        $sender = ['id' => $same ? $first['id'] : (string) Str::uuid(), 'driver' => $driver,
            'config' => $this->checked($driver, $input, $same ? $first['config'] : [], $testTo)];
        $this->senders->put([$sender, ...($first === null ? [] : array_slice($all, 1))]);
    }

    public function remove(string $id): void
    {
        $all = $this->senders->all();
        array_splice($all, $this->at($all, $id), 1);
        $this->senders->put($all);
    }

    /** @param  list<string>  $ids  every sender's id, in the new order */
    public function order(array $ids): void
    {
        $all = $this->senders->all();
        $byId = array_column($all, null, 'id');
        if (count($ids) !== count($all) || count(array_unique($ids)) !== count($ids) || array_diff($ids, array_keys($byId)) !== []) {
            throw new EmailRefused(422, __('emails.order_mismatch'));
        }
        $this->senders->put(array_map(fn (string $id) => $byId[$id], $ids));
    }

    /** Check a sender's form and send it one test; answers the config to save. */
    private function checked(string $driver, array $input, array $saved, string $testTo): array
    {
        $from = $this->senders->from();
        if ($from['address'] === null) {
            throw new EmailRefused(409, __('emails.no_from'));
        }
        $config = $this->senders->check($driver, $input, $saved);
        try {
            $this->emails->test($driver, $config, ['address' => $from['address'], 'name' => $from['name']], $testTo);
        } catch (EmailFailed $e) {
            throw new EmailRefused(422, __('emails.test_failed', ['reason' => Str::limit($e->getMessage(), config('kit.email.error_max_length'))]));
        }

        return $config;
    }

    private function at(array $all, string $id): int
    {
        foreach ($all as $i => $sender) {
            if ($sender['id'] === $id) {
                return $i;
            }
        }
        throw new EmailRefused(404, __('emails.unknown_sender'));
    }

    private function names(): array
    {
        return array_map(fn (string $f) => __("emails.fields.{$f}"), ['siteUrl' => 'siteUrl', 'fromAddress' => 'fromAddress', 'fromName' => 'fromName', 'driver' => 'driver']);
    }
}
