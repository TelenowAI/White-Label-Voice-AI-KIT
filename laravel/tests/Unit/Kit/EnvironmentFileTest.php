<?php

namespace Tests\Unit\Kit;

use App\Kit\Setup\EnvironmentFile;
use Dotenv\Dotenv;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EnvironmentFile: every value it writes is read back unchanged by the dotenv parser Laravel uses (a database
 * password can hold any character), and a write changes only the keys it names.
 */
class EnvironmentFileTest extends TestCase
{
    public static function values(): array
    {
        return [
            'plain' => ['s3cret-Pass.1'],
            'an app key' => ['base64:'.base64_encode(str_repeat("\xff", 32))],
            'spaces and a hash' => ['my pass # not a comment'],
            'a dollar that would expand' => ['pa$HOME${B}ss'],
            'double quotes' => ['say "hi"'],
            'a single quote' => ["it's"],
            'both quotes, a backslash and a dollar' => ['it\'s "x" \\ $Y ${B}'],
            'empty' => [''],
        ];
    }

    #[DataProvider('values')]
    public function test_the_parser_reads_back_exactly_what_was_written(string $value): void
    {
        $parsed = Dotenv::parse("B=bee\nK=".EnvironmentFile::quote($value)."\n");

        $this->assertSame($value, $parsed['K']);
    }

    public function test_a_value_with_a_control_character_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EnvironmentFile::quote("line1\nline2");
    }

    public function test_a_write_replaces_its_keys_appends_new_ones_and_keeps_every_other_line(): void
    {
        $file = new EnvironmentFile;
        file_put_contents($file->path(), "APP_NAME=Kit\n# a comment\nDB_HOST=old\nexport DB_PORT=1\n");

        $file->set(['DB_HOST' => 'db.internal', 'DB_PORT' => '3306', 'DB_PASSWORD' => 'p w']);

        $this->assertSame("APP_NAME=Kit\n# a comment\nDB_HOST=db.internal\nDB_PORT=3306\nDB_PASSWORD='p w'\n", file_get_contents($file->path()));
        $this->assertSame([], glob(dirname($file->path()).'/.env-*'), 'no temporary file is left behind');
    }

    public function test_it_makes_the_file_when_there_is_none(): void
    {
        $file = new EnvironmentFile;
        $this->assertFileDoesNotExist($file->path());

        $file->set(['APP_KEY' => 'base64:abc=']);

        $this->assertSame("APP_KEY=base64:abc=\n", file_get_contents($file->path()));
    }

    public function test_it_says_when_it_cannot_write(): void
    {
        config(['kit.setup.env_file' => $this->scratch.'/no-such-folder/.env']);
        $file = new EnvironmentFile;

        $this->assertFalse($file->writable());
        $this->expectException(\RuntimeException::class);
        $file->set(['APP_KEY' => 'x']);
    }
}
