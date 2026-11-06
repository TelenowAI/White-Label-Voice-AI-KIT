<?php

namespace App\Kit\Setup;

use InvalidArgumentException;
use RuntimeException;

/**
 * The environment file (`.env`) the setup wizard writes: the install's APP_KEY and its database. On shared hosting
 * there is no shell, so the wizard is the only way these get set (SELF_HOSTED_KIT_PLAN.md §3.3).
 *
 * A write replaces the named keys' lines (or appends them) and leaves every other line as it was; it goes to a
 * temporary file that is then renamed over the old one, so a reader never sees half a file. Each value is
 * written so the dotenv parser reads back exactly that value (quote()).
 *
 * Not here: deciding the values (the steps and PrepareInstallation). Called by: DatabaseStep, PrepareInstallation,
 * EnvironmentStep (is it writable).
 */
final class EnvironmentFile
{
    public function path(): string
    {
        return config('kit.setup.env_file') ?? app()->environmentFilePath();
    }

    public function writable(): bool
    {
        $path = $this->path();

        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    /** @param array<string, string> $values */
    public function set(array $values): void
    {
        $path = $this->path();
        $text = is_file($path) ? (string) file_get_contents($path) : '';
        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException("Not an environment variable name: {$key}");
            }
            $line = $key.'='.self::quote($value);
            $pattern = '/^(?:export\s+)?'.$key.'\s*=.*$/m';
            if (preg_match($pattern, $text)) {
                $text = preg_replace_callback($pattern, fn () => $line, $text);
            } else {
                $text = ($text === '' || str_ends_with($text, "\n") ? $text : $text."\n").$line."\n";
            }
        }
        $dir = dirname($path);
        $tmp = @tempnam($dir, '.env-');
        if ($tmp === false || @file_put_contents($tmp, $text) === false) {
            throw new RuntimeException("Cannot write {$path}");
        }
        @chmod($tmp, is_file($path) ? (fileperms($path) & 0777) : 0600);
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write {$path}");
        }
    }

    /**
     * `$value` as the dotenv parser reads it back unchanged: bare when it is plain; single-quoted (fully literal)
     * when it has no single quote; otherwise double-quoted with `\`, `"` and `$` escaped (`${X}` would expand).
     */
    public static function quote(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('An environment value cannot hold control characters.');
        }
        if (preg_match('/^[A-Za-z0-9_.:\/@+=,-]*$/', $value)) {
            return $value;
        }
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']).'"';
    }
}
