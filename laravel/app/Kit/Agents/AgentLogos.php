<?php

namespace App\Kit\Agents;

use App\Models\Agent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * An agent's logo (contract/kit-api.yaml setAgentLogo, removeAgentLogo, getAgentLogo; owner's choice 11-09: kept in the kit, never at the voice
 * platform, so no platform address shows in the console or on customers' websites). Shown in the console's agent list and page, and at the top of
 * the agent's chat box on customers' websites.
 *
 * - set(): a PNG, JPEG or WebP image (its type read from its bytes, not its name: never an SVG, which can carry script), of at most
 *   config('kit.agents.logo.max_kb') kilobytes and min_px to max_px pixels a side, kept on config('kit.agents.logo.disk') under
 *   `<dir>/<agent>/<its content's hash>.<type>`; the image before is removed.
 * - remove(), forget(): none from now on (forget(): an agent deleted).
 * - url(): where it is served (AgentLogoController: anyone, as a chat box on another website shows it), or null.
 * - copy(): the same image for a duplicate (its own file: removing one never removes the other's).
 *
 * Not here: the pages (AgentsController, AgentLogoController), the chat box (App\Kit\Chat\Conversations).
 */
final class AgentLogos
{
    public function set(Agent $agent, mixed $file): Agent
    {
        $c = config('kit.agents.logo');
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:'.$c['max_kb']]], [], ['file' => __('agents.logo.field')])->validate();
        /** @var UploadedFile $file */
        $size = @getimagesize($file->getRealPath());
        $type = is_array($size) ? ($c['types'][$size['mime'] ?? ''] ?? null) : null;
        if ($type === null) {
            throw ValidationException::withMessages(['file' => [__('agents.logo.type')]]);
        }
        if (min($size[0], $size[1]) < $c['min_px'] || max($size[0], $size[1]) > $c['max_px']) {
            throw ValidationException::withMessages(['file' => [__('agents.logo.size', ['min' => $c['min_px'], 'max' => $c['max_px']])]]);
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $path = "{$c['dir']}/{$agent->id}/".substr(hash('sha256', $bytes), 0, 32).".{$type}";
        $disk = Storage::disk($c['disk']);
        if (! $disk->put($path, $bytes)) {
            throw new AgentRefused(503, __('agents.logo.not_kept'));
        }
        $before = $agent->logo;
        $agent->forceFill(['logo' => $path])->save();
        if (is_string($before) && $before !== $path) {
            $disk->delete($before);
        }

        return $agent;
    }

    public function remove(Agent $agent): Agent
    {
        $this->forget($agent);

        return $agent;
    }

    /** The agent's image removed (an agent deleted, or its logo taken off). */
    public function forget(Agent $agent): void
    {
        if (is_string($agent->logo)) {
            Storage::disk(config('kit.agents.logo.disk'))->delete($agent->logo);
            $agent->forceFill(['logo' => null])->save();
        }
    }

    /** The same image for another agent (a duplicate), in its own file. */
    public function copy(Agent $from, Agent $to): void
    {
        if (! is_string($from->logo)) {
            return;
        }
        $disk = Storage::disk(config('kit.agents.logo.disk'));
        $path = config('kit.agents.logo.dir')."/{$to->id}/".basename($from->logo);
        if ($disk->exists($from->logo) && $disk->copy($from->logo, $path)) {
            $to->forceFill(['logo' => $path])->save();
        }
    }

    /** Where the logo is served, or null for none. */
    public function url(Agent $agent): ?string
    {
        return is_string($agent->logo) ? url('/api/kit/media/agent-logos/'.$agent->id.'/'.basename($agent->logo)) : null;
    }
}
