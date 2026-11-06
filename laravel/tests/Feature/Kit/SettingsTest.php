<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Settings fail soft only before the install: with no database yet a read answers "not set" (the wizard and the
 * brand answer from the first visit), but once the install is finished a failed read is an error, never a silent
 * default.
 */
class SettingsTest extends TestCase
{
    public function test_before_the_install_a_read_with_no_database_answers_the_default(): void
    {
        $this->assertSame('fallback', app(Settings::class)->get('brand.name', 'fallback'));
        $this->assertFalse(app(Settings::class)->has('brand.name'));
    }

    public function test_after_the_install_a_failed_read_is_an_error(): void
    {
        touch(config('kit.installed_marker'));
        if (Schema::hasTable('settings')) {
            $this->markTestSkipped('Needs a database with no tables: on a shared test database, run this test first.');
        }

        $this->expectException(QueryException::class);
        app(Settings::class)->get('brand.name', 'fallback');
    }
}
