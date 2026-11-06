<?php

namespace App\Kit\Sms;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Changing how the kit sends texts (Admin → SMS). A sender is never saved before it has sent one test text to the
 * number the staff member gives (`testNumber`, not kept), so a mistyped address or key is caught at once, in the
 * provider's own words. Up to config('kit.sms.max_senders'), in the order they are tried.
 *
 * Not here: sending (Sms), the stored list (SmsSenders), the page (Admin\SmsController).
 */
final class SmsSetup
{
    public function __construct(private readonly SmsSenders $senders, private readonly Sms $sms) {}

    /** Add a sender after the others. Answers it. */
    public function add(array $input): array
    {
        $all = $this->senders->all();
        if (count($all) >= config('kit.sms.max_senders')) {
            throw new SmsRefused(409, __('sms.too_many', ['max' => config('kit.sms.max_senders')]));
        }
        $driver = Validator::make($input, ['driver' => ['required', 'string', Rule::in(array_keys(config('kit.sms.drivers')))]],
            [], ['driver' => __('sms.fields.driver')])->validate()['driver'];
        $sender = ['id' => (string) Str::uuid(), 'driver' => $driver, 'config' => $this->checked($driver, $input, [])];
        $this->senders->put([...$all, $sender]);

        return $sender;
    }

    /** Change one sender's settings (its driver stays; a secret left empty is kept). Answers it. */
    public function update(string $id, array $input): array
    {
        $all = $this->senders->all();
        $at = $this->at($all, $id);
        $all[$at]['config'] = $this->checked($all[$at]['driver'], $input, $all[$at]['config']);
        $this->senders->put($all);

        return $all[$at];
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
            throw new SmsRefused(422, __('sms.order_mismatch'));
        }
        $this->senders->put(array_map(fn (string $id) => $byId[$id], $ids));
    }

    /** The number a test goes to, checked. */
    public function testNumber(array $input): string
    {
        $number = PhoneNumber::normalize($input['testNumber'] ?? null);
        if ($number === null) {
            throw ValidationException::withMessages(['testNumber' => [__('sms.number_shape')]]);
        }

        return $number;
    }

    /** Check a sender's form and send it one test; answers the config to save. */
    private function checked(string $driver, array $input, array $saved): array
    {
        $to = $this->testNumber($input);
        $config = $this->senders->check($driver, $input, $saved);
        try {
            $this->sms->test($driver, $config, $to);
        } catch (SmsFailed $e) {
            throw new SmsRefused(422, __('sms.test_failed', ['reason' => Str::limit($e->getMessage(), config('kit.sms.error_max_length'))]));
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
        throw new SmsRefused(404, __('sms.unknown_sender'));
    }
}
