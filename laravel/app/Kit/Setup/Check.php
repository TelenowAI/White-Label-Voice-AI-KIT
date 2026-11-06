<?php

namespace App\Kit\Setup;

/**
 * One finding a setup step reports (contract/kit-api.yaml SetupCheck): `ok`, a `warning` the operator should
 * know about, or `blocking` — something to fix outside the wizard before it can go on (the step is then
 * `blocked`). Made by the steps; read by SetupWizard.
 */
final class Check
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const BLOCKING = 'blocking';

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $level,
        public readonly ?string $detail = null,
    ) {
        if (! in_array($level, [self::OK, self::WARNING, self::BLOCKING], true)) {
            throw new \InvalidArgumentException("Unknown check level {$level}");
        }
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'level' => $this->level, 'detail' => $this->detail];
    }
}
