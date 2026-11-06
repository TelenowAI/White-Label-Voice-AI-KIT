<?php

namespace Tests\Feature\Kit;

use App\Kit\Pricing\PricingEngine;
use App\Kit\Pricing\PricingRefused;
use App\Kit\Pricing\TelenowItems;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Laravel pricing engine against the shared fixtures (contract/pricing/fixtures, the same files the reference
 * engine is held to): every case answered exactly — the same lines in the same order, every amount the same string,
 * or the same refusal. Compared with assertSame on key-sorted arrays: PHP's loose `==` would call "0.49" and
 * "0.490" equal.
 */
class PricingFixturesTest extends TestCase
{
    private static function contract(string $path = ''): string
    {
        return dirname(__DIR__, 4).'/contract'.($path === '' ? '' : "/{$path}");
    }

    /** @return array<string, array{0: array}> */
    public static function fixtures(): array
    {
        $out = [];
        foreach (glob(self::contract('pricing/fixtures/*.json')) as $file) {
            $out[basename($file, '.json')] = [json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)];
        }

        return $out;
    }

    private static function cassette(string $name): mixed
    {
        return json_decode(file_get_contents(self::contract("telenow-mock/cassettes/{$name}.json")), true, flags: JSON_THROW_ON_ERROR)['response']['body'];
    }

    private function item(array $spec): array
    {
        if (isset($spec['call'])) {
            return TelenowItems::call(self::cassette($spec['call']['cost'])['data'], self::cassette($spec['call']['call']));
        }
        if (isset($spec['fee'])) {
            $entries = array_values(array_filter(
                self::cassette($spec['fee']['ledger'])['data']['entries'],
                fn (array $e) => $e['refKind'] === $spec['fee']['refKind'],
            ));
            $this->assertCount(1, $entries);

            return TelenowItems::fee($entries[0]);
        }
        if (isset($spec['ledgerEntry'])) {
            return TelenowItems::fee($spec['ledgerEntry']);
        }

        return $spec['inline'];
    }

    /** Associative arrays key-sorted, lists kept in order, so assertSame compares content and types. */
    private static function canon(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(self::canon(...), $v);
        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return $v;
    }

    #[DataProvider('fixtures')]
    public function test_the_fixture_is_answered_exactly(array $fixture): void
    {
        $lists = array_map(
            fn (string $f) => json_decode(file_get_contents($f), true, flags: JSON_THROW_ON_ERROR),
            glob(self::contract('pricing/price-lists/*.json')),
        );
        try {
            $answer = (new PricingEngine)->price(
                [...$lists, ...($fixture['priceLists'] ?? [])], $fixture['plan'], $fixture['usdRate'], $this->item($fixture['item']),
            );
        } catch (PricingRefused $e) {
            $answer = ['error' => $e->refusal, 'subject' => $e->subject];
        }

        $this->assertSame(self::canon($fixture['expect']), self::canon($answer));
    }

    public function test_there_are_fixtures(): void
    {
        $this->assertNotEmpty(self::fixtures());
    }

    public function test_money_is_only_a_plain_decimal_string(): void
    {
        foreach (['1e3', '0.1.2', '.5', '5.', '+1', ' 1', '01', ''] as $bad) {
            try {
                PricingEngine::decimal($bad);
                $this->fail("accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('-0.004125', PricingEngine::short(PricingEngine::decimal('-0.0041250000')));
    }
}
