<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Every test runs against throwaway paths: the environment file the setup wizard writes, the install lock, and the
 * folder SQLite databases are made in all point into a fresh temporary folder, so no test can touch this checkout's
 * real `.env`, `database/` or install state. `$this->scratch` is that folder.
 */
abstract class TestCase extends BaseTestCase
{
    protected string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/voice-kit-test-'.bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
        config([
            'kit.setup.env_file' => $this->scratch.'/.env',
            'kit.installed_marker' => $this->scratch.'/installed',
            'kit.setup.sqlite_dir' => $this->scratch,
            // Never the real Telenow: a test that calls it fakes it (Tests\Support\RecordedTelenow).
            'kit.telenow.base_url' => 'https://telenow.test',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch.'/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->scratch);
        parent::tearDown();
    }
}
