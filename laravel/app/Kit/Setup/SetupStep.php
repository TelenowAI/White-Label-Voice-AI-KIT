<?php

namespace App\Kit\Setup;

use Illuminate\Http\Request;

/**
 * One step of the setup wizard. A step declares its own form (fields), what it found (checks, facts) and what
 * it does with a submitted form; the wizard runs the steps in config('kit.setup.steps') order and the console
 * renders whatever they declare (contract/kit-api.yaml SetupStep). Its title, description and labels live in
 * lang/<locale>/setup.php under its id.
 *
 * `$mayRead`: whether the person asking may see what the step has saved (the signed-in owner; anyone before
 * there is an owner, for the steps that run then). Without it a step shows its form empty and no facts.
 *
 * Not here: the order, who may submit, the HTTP answers (SetupWizard, SetupController).
 */
abstract class SetupStep
{
    /** Runs before there is an owner, and closes once there is one. */
    public const ACCESS_ANYONE = 'anyone';

    /** The signed-in owner only. */
    public const ACCESS_OWNER = 'owner';

    abstract public function id(): string;

    /** Is this step's work saved? (A failed blocking check makes the step `blocked` whatever this says.) */
    abstract public function done(): bool;

    public function access(): string
    {
        return self::ACCESS_OWNER;
    }

    /** False for a step that only reports. */
    public function submittable(): bool
    {
        return true;
    }

    /** @return list<Field> */
    public function fields(Request $request, bool $mayRead): array
    {
        return [];
    }

    /** @return list<Check> */
    public function checks(Request $request, bool $mayRead): array
    {
        return [];
    }

    /** @return list<array{label: string, value: string}> */
    public function facts(Request $request): array
    {
        return [];
    }

    /**
     * Do the step with the submitted form (`$input`: the request's JSON body). Throws a ValidationException
     * naming the refused fields, or SetupRefused.
     */
    public function submit(Request $request, array $input): void {}

    public function title(): string
    {
        return __("setup.steps.{$this->id()}.title");
    }

    public function description(): string
    {
        return __("setup.steps.{$this->id()}.description");
    }

    /** A label or help text of this step's own, from lang/<locale>/setup.php. */
    protected function text(string $key, array $replace = []): string
    {
        return __("setup.steps.{$this->id()}.{$key}", $replace);
    }
}
