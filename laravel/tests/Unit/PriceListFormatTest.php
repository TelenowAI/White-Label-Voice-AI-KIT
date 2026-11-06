<?php

namespace Tests\Unit;

use App\Kit\Pricing\PriceListFormat;
use App\Kit\Support\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The kit's check of a price list an operator saves agrees with the format's schema on every shared case
 * (contract/pricing/list-cases.json, which contract/tests/pricing.test.ts holds the schema itself to), and every
 * example list passes it.
 */
class PriceListFormatTest extends TestCase
{
    public static function cases(): array
    {
        $file = Json::decode((string) file_get_contents(__DIR__.'/../../../contract/pricing/list-cases.json'));

        return array_column(array_map(fn (array $c) => [$c['name'], $c['list'], $c['valid']], $file['cases']), null, 0);
    }

    #[DataProvider('cases')]
    public function test_agrees_with_the_schema(string $name, mixed $list, bool $valid): void
    {
        $problems = PriceListFormat::problems($list);
        $this->assertSame($valid, $problems === [], $name.': '.json_encode($problems));
    }

    public function test_every_example_list_passes(): void
    {
        foreach (glob(__DIR__.'/../../../contract/pricing/price-lists/*.json') as $file) {
            $this->assertSame([], PriceListFormat::problems(Json::decode((string) file_get_contents($file))), basename($file));
        }
    }
}
