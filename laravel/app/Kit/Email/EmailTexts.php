<?php

namespace App\Kit\Email;

use App\Kit\Settings\Settings;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Each email's words (Admin → Email → Templates): the kit's own (lang/<locale>/emails.php `kinds.<kind>`) unless the
 * operator wrote their own, kept per language (setting `email.texts.<locale>.<kind>`). An email's words are its
 * subject, its paragraphs, its button's words (only for an email that has a button: it opens the link the email is
 * about) and the paragraphs after the button.
 *
 * The placeholders an email can use are `:brand` (always the brand's name) and those its own words use. The operator's
 * words may use only those, since one the email cannot fill would reach the reader as typed. They are filled as
 * Laravel fills a translation's: `:name` as it is, `:Name` capitalised, `:NAME` in capitals.
 *
 * Not here: the layout and the brand (EmailTemplate), sending (Emails), which emails are sent (their callers).
 */
final class EmailTexts
{
    private const KEY = 'email.texts.';

    private const PLACEHOLDER = '/:([A-Za-z][A-Za-z0-9_]*)/';

    public function __construct(private readonly Settings $settings) {}

    /** @return list<string> every kind of email, in the order of the words file */
    public function kinds(): array
    {
        return array_keys((array) __('emails.kinds'));
    }

    public function exists(string $kind): bool
    {
        return in_array($kind, $this->kinds(), true);
    }

    /** @return array{subject: string, lines: list<string>, action: ?string, after: list<string>} the kit's own words */
    public function defaults(string $kind): array
    {
        $texts = (array) __("emails.kinds.{$kind}");

        return [
            'subject' => (string) ($texts['subject'] ?? ''),
            'lines' => array_values($texts['lines'] ?? []),
            'action' => $texts['action'] ?? null,
            'after' => array_values($texts['after'] ?? []),
        ];
    }

    /** The operator's own words for the email, or null while it has the kit's. */
    public function edited(string $kind): ?array
    {
        $saved = $this->settings->get($this->key($kind));

        return is_array($saved) ? $saved : null;
    }

    /** The words the email is sent with. */
    public function current(string $kind): array
    {
        return $this->edited($kind) ?? $this->defaults($kind);
    }

    /** @return list<string> the placeholders it can use: `brand`, then those its own words use, in order */
    public function placeholders(string $kind): array
    {
        $names = ['brand'];
        foreach ($this->strings($this->defaults($kind)) as $text) {
            preg_match_all(self::PLACEHOLDER, $text, $found);
            foreach ($found[1] as $name) {
                $names[] = Str::lower($name);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Check the operator's words for an email: answers them as they are kept (each trimmed), or throws a
     * ValidationException naming each field that is wrong.
     *
     * @return array{subject: string, lines: list<string>, action: ?string, after: list<string>}
     */
    public function check(string $kind, array $input): array
    {
        $limits = config('kit.email.template');
        $hasButton = $this->defaults($kind)['action'] !== null;
        $errors = [];

        $subject = is_string($input['subject'] ?? null) ? trim($input['subject']) : '';
        if ($subject === '' || mb_strlen($subject) > $limits['subject_max_length'] || preg_match('/[\r\n]/', $subject)) {
            $errors['subject'][] = __('emails.template.subject_required', ['max' => $limits['subject_max_length']]);
        }
        $lines = $this->paragraphs($input['lines'] ?? null, $limits);
        if ($lines === null || $lines === []) {
            $errors['lines'][] = __('emails.template.lines_required', ['max' => $limits['max_lines'], 'length' => $limits['line_max_length']]);
        }
        $after = $this->paragraphs($input['after'] ?? [], $limits);
        if ($after === null) {
            $errors['after'][] = __('emails.template.after_shape', ['max' => $limits['max_lines'], 'length' => $limits['line_max_length']]);
        }
        $action = is_string($input['action'] ?? null) ? trim($input['action']) : null;
        if ($hasButton && ($action === null || $action === '' || mb_strlen($action) > $limits['action_max_length'])) {
            $errors['action'][] = __('emails.template.action_required', ['max' => $limits['action_max_length']]);
        } elseif (! $hasButton && ($input['action'] ?? null) !== null && $input['action'] !== '') {
            $errors['action'][] = __('emails.template.no_action');
        }

        $known = $this->placeholders($kind);
        foreach (['subject' => [$subject], 'lines' => $lines ?? [], 'action' => $hasButton ? [(string) $action] : [], 'after' => $after ?? []] as $field => $texts) {
            $unknown = $this->unknown($texts, $known);
            if ($unknown !== []) {
                $errors[$field][] = __('emails.template.unknown_placeholder', [
                    'placeholders' => implode(', ', array_map(fn ($p) => ":{$p}", $unknown)),
                    'known' => implode(', ', array_map(fn ($p) => ":{$p}", $known)),
                ]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['subject' => $subject, 'lines' => $lines, 'action' => $hasButton ? $action : null, 'after' => $after];
    }

    /** Check and keep the operator's words for an email (check()). */
    public function save(string $kind, array $input): array
    {
        $texts = $this->check($kind, $input);
        $this->settings->put($this->key($kind), $texts);

        return $texts;
    }

    /** Go back to the kit's own words for an email. */
    public function reset(string $kind): void
    {
        $this->settings->forget($this->key($kind));
    }

    /**
     * An email's words with its placeholders filled.
     *
     * @param  array{subject: string, lines: list<string>, action: ?string, after: list<string>}  $texts
     */
    public function filled(array $texts, array $vars): array
    {
        return [
            'subject' => $this->fill($texts['subject'], $vars),
            'lines' => array_map(fn (string $l) => $this->fill($l, $vars), $texts['lines']),
            'action' => $texts['action'] === null ? null : $this->fill($texts['action'], $vars),
            'after' => array_map(fn (string $l) => $this->fill($l, $vars), $texts['after']),
        ];
    }

    /** `$text` with each placeholder filled, as Laravel's translator fills one (Translator::makeReplacements). */
    private function fill(string $text, array $vars): string
    {
        $replace = [];
        foreach ($vars as $key => $value) {
            $value = (string) ($value ?? '');
            $replace[':'.Str::ucfirst($key)] = Str::ucfirst($value);
            $replace[':'.Str::upper($key)] = Str::upper($value);
            $replace[':'.$key] = $value;
        }

        return $replace === [] ? $text : strtr($text, $replace);
    }

    /** A list of paragraphs, each trimmed, non-empty and within the limits; null when it is not one. */
    private function paragraphs(mixed $value, array $limits): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $limits['max_lines']) {
            return null;
        }
        $lines = [];
        foreach ($value as $line) {
            $line = is_string($line) ? trim($line) : '';
            if ($line === '' || mb_strlen($line) > $limits['line_max_length']) {
                return null;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /** @return list<string> the placeholders in `$texts` that are none of `$known` in a form it fills */
    private function unknown(array $texts, array $known): array
    {
        $forms = [];
        foreach ($known as $name) {
            $forms[$name] = $forms[Str::ucfirst($name)] = $forms[Str::upper($name)] = true;
        }
        $unknown = [];
        foreach ($texts as $text) {
            preg_match_all(self::PLACEHOLDER, $text, $found);
            foreach ($found[1] as $name) {
                if (! isset($forms[$name])) {
                    $unknown[] = $name;
                }
            }
        }

        return array_values(array_unique($unknown));
    }

    /** @return list<string> */
    private function strings(array $texts): array
    {
        return array_values(array_filter([$texts['subject'], ...$texts['lines'], $texts['action'], ...$texts['after']], 'is_string'));
    }

    private function key(string $kind): string
    {
        return self::KEY.app()->getLocale().'.'.$kind;
    }
}
