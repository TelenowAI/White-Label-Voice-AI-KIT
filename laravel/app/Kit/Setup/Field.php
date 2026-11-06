<?php

namespace App\Kit\Setup;

/**
 * One field of a form the backend declares and the console renders (contract/kit-api.yaml SetupField): a setup
 * step's, an email sender's, the sign-up form's. A password or secret field never carries its value; `secretSet` says
 * one is saved, and leaving the field empty keeps it. Made by the steps, the email and payment drivers, SignupSettings and
 * App\Kit\Agents\AgentBehaviour.
 */
final class Field
{
    /**
     * `checkbox`: ticked sends "1", unticked "" (a sign-up form's terms). `textarea`: text over several lines. `tel`: a
     * phone number (signing up with a phone). `number`: a whole number, typed on a phone's number pad.
     */
    public const TYPES = ['text', 'email', 'password', 'secret', 'url', 'color', 'select', 'checkbox', 'textarea', 'tel', 'number'];

    /**
     * @param  list<array{value: string, label: string}>  $options
     * @param  array{field: string, values: list<string>}|null  $showWhen
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $required = false,
        public readonly ?string $value = null,
        public readonly ?string $help = null,
        public readonly array $options = [],
        public readonly ?array $showWhen = null,
        public readonly bool $secretSet = false,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown field type {$type}");
        }
    }

    public function toArray(): array
    {
        $hidden = in_array($this->type, ['password', 'secret'], true);

        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'value' => $hidden ? null : $this->value,
            'help' => $this->help,
            'options' => $this->options,
            'showWhen' => $this->showWhen,
            'secretSet' => $this->secretSet,
        ];
    }
}
