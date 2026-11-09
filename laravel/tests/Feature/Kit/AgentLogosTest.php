<?php

namespace Tests\Feature\Kit;

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\TestImages;
use Tests\TestCase;

/**
 * An agent's logo (App\Kit\Agents\AgentLogos; contract/kit-api.yaml setAgentLogo, removeAgentLogo, getAgentLogo; owner's choice 11-09: kept in the
 * kit): a PNG, JPEG or WebP image judged by its bytes, within the size limits, kept under its content's name and served to anyone — only the agent's
 * current image, as an image that runs nothing — the one before removed. The kit's own: nothing is sent to the voice platform.
 */
class AgentLogosTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Workspace $acme;

    private Agent $agent;

    private Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->disk = Storage::fake(config('kit.agents.logo.disk'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => 'vai_test_customer_acme'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    private function upload(UploadedFile $file, ?Agent $agent = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->ana)->post('/api/kit/console/agents/'.($agent ?? $this->agent)->id.'/logo', ['file' => $file], ['Accept' => 'application/json']);
    }

    /** The served path of a logo URL. */
    private static function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    public function test_an_image_is_kept_under_its_contents_name_and_served_to_anyone_as_an_image_that_runs_nothing(): void
    {
        $bytes = TestImages::png(64, 64);
        $png = TestImages::file('anything.gif', $bytes); // its name says GIF; its bytes are PNG
        $url = $this->upload($png)->assertOk()->json('logoUrl');
        $name = substr(hash('sha256', $bytes), 0, 32).'.png';
        $this->assertSame([config('kit.agents.logo.dir')."/{$this->agent->id}/{$name}", url("/api/kit/media/agent-logos/{$this->agent->id}/{$name}")], [$this->agent->fresh()->logo, $url]);
        $this->assertSame($bytes, $this->disk->get($this->agent->fresh()->logo));

        auth()->forgetGuards();
        $served = $this->get(self::path($url))->assertOk();
        $this->assertSame($bytes, $served->getContent());
        $this->assertSame(['image/png', 'nosniff', "default-src 'none'", 'cross-origin'], [$served->headers->get('Content-Type'), $served->headers->get('X-Content-Type-Options'),
            $served->headers->get('Content-Security-Policy'), $served->headers->get('Cross-Origin-Resource-Policy')]);
        $this->assertStringContainsString('max-age='.config('kit.agents.logo.cache_seconds'), (string) $served->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $served->headers->get('Cache-Control'));
        $this->assertNull($served->headers->get('Set-Cookie'), 'no session');

        $jpeg = TestImages::file('new.jpg', TestImages::jpeg());
        $next = $this->upload($jpeg)->assertOk()->json('logoUrl');
        $this->assertStringEndsWith('.jpg', $next);
        $this->get(self::path($next))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(self::path($url))->assertNotFound();
        $this->assertSame([basename($this->agent->fresh()->logo)], array_map('basename', $this->disk->files(config('kit.agents.logo.dir')."/{$this->agent->id}")), 'the one before removed');
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$this->agent->id}")->assertOk()->assertJsonPath('logoUrl', $next);
        $this->assertStringEndsWith('.webp', $this->upload(TestImages::file('w.webp', TestImages::webp()))->assertOk()->json('logoUrl'));
    }

    public function test_only_a_png_jpeg_or_webp_within_the_limits_is_kept(): void
    {
        $c = config('kit.agents.logo');
        $refused = fn (UploadedFile $file, string $message) => $this->upload($file)->assertStatus(422)->assertJsonPath('errors.file', [$message]);
        $refused(UploadedFile::fake()->createWithContent('logo.png', 'not an image at all'), __('agents.logo.type'));
        $refused(UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><script>alert(1)</script></svg>'), __('agents.logo.type'));
        $refused(TestImages::file('wide.png', TestImages::png($c['max_px'] + 1, 20)), __('agents.logo.size', ['min' => $c['min_px'], 'max' => $c['max_px']]));
        $refused(TestImages::file('tiny.png', TestImages::png(64, $c['min_px'] - 1)), __('agents.logo.size', ['min' => $c['min_px'], 'max' => $c['max_px']]));
        $this->upload(TestImages::file('edge.png', TestImages::png($c['max_px'], $c['min_px'])))->assertOk();
        $this->disk->deleteDirectory($c['dir']);
        $this->agent->forceFill(['logo' => null])->save();
        $refused(TestImages::file('logo.gif', TestImages::gif()), __('agents.logo.type'));
        config(['kit.agents.logo.max_kb' => 1]);
        $heavy = TestImages::file('heavy.png', TestImages::png(64, 64, noise: true)); // a real image, over a kilobyte
        $this->assertGreaterThan(1024, $heavy->getSize());
        $this->upload($heavy)->assertStatus(422)->assertJsonPath('errors.file', [__('validation.max.file', ['attribute' => __('agents.logo.field'), 'max' => 1])]);
        config(['kit.agents.logo.max_kb' => $c['max_kb']]);
        $this->actingAs($this->ana)->post("/api/kit/console/agents/{$this->agent->id}/logo", [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame([[], null], [$this->disk->allFiles(), $this->agent->fresh()->logo], 'nothing kept');
    }

    public function test_only_the_agents_current_image_is_served_and_a_logo_taken_off_is_gone(): void
    {
        $url = $this->upload(TestImages::file('logo.png', TestImages::png(64, 64)))->assertOk()->json('logoUrl');
        $file = basename($url);
        $other = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Billing desk']);
        $this->disk->put(config('kit.agents.logo.dir')."/{$other->id}/{$file}", 'x');
        $this->get("/api/kit/media/agent-logos/{$other->id}/{$file}")->assertNotFound();
        $this->get('/api/kit/media/agent-logos/not-an-id/'.$file)->assertNotFound();
        $this->get("/api/kit/media/agent-logos/{$this->agent->id}/".str_repeat('a', 32).'.png')->assertNotFound();
        $this->get("/api/kit/media/agent-logos/{$this->agent->id}/../logo.png")->assertNotFound();

        $stranger = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $theirs = Workspace::create(['name' => 'Bright Smiles', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $theirs->id, 'user_id' => $stranger->id, 'role' => 'owner']);
        $this->upload(TestImages::file('logo.png', TestImages::png(64, 64)), as: $stranger)->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/api/kit/console/agents/{$this->agent->id}/logo")->assertNotFound();

        $this->agent->forceFill(['deleted_at' => now()])->save();
        $this->get(self::path($url))->assertNotFound();
        $this->agent->forceFill(['deleted_at' => null])->save();
        $this->get(self::path($url))->assertOk();
        $kept = $this->agent->fresh()->logo;
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->agent->id}/logo")->assertOk()->assertJsonPath('logoUrl', null);
        $this->assertSame([null, false], [$this->agent->fresh()->logo, $this->disk->exists($kept)]);
        $this->get(self::path($url))->assertNotFound();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->agent->id}/logo")->assertOk()->assertJsonPath('logoUrl', null);
    }
}
