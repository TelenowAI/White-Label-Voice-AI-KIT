<?php

namespace App\Kit\Setup\Steps;

use App\Kit\Settings\Settings;
use App\Kit\Setup\Installation;
use App\Kit\Setup\SetupStep;
use Illuminate\Http\Request;

/**
 * The wizard's last step (SELF_HOSTED_KIT_PLAN.md §4.1.13): record when the install finished and write the lock
 * (a file and a setting, §3.6). From then on the wizard's routes answer 404 and the console opens the admin panel.
 */
final class FinishStep extends SetupStep
{
    public function __construct(private readonly Settings $settings, private readonly Installation $installation) {}

    public function id(): string
    {
        return 'finish';
    }

    public function done(): bool
    {
        return $this->installation->installed();
    }

    public function submit(Request $request, array $input): void
    {
        $this->settings->put('setup.finished_at', now()->toIso8601String());
        $this->installation->lock();
    }
}
