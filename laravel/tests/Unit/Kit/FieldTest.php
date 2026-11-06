<?php

namespace Tests\Unit\Kit;

use App\Kit\Setup\Field;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A setup field never carries a password or secret value to the browser, whatever a step gives it. */
class FieldTest extends TestCase
{
    public static function hidden(): array
    {
        return ['password' => ['password'], 'secret' => ['secret']];
    }

    #[DataProvider('hidden')]
    public function test_a_hidden_field_is_sent_without_its_value(string $type): void
    {
        $this->assertNull((new Field('k', 'K', $type, false, 'hunter2'))->toArray()['value']);
    }

    public function test_any_other_field_is_sent_with_its_value(): void
    {
        $this->assertSame('Acme', (new Field('name', 'Name', 'text', true, 'Acme'))->toArray()['value']);
    }
}
