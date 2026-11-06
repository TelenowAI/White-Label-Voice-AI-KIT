<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Audit\Audit;
use App\Kit\Email\EmailTemplate;
use App\Kit\Email\EmailTexts;
use App\Kit\Email\Senders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Email → Templates (contract/kit-api.yaml listEmailTemplates, saveEmailTemplate, resetEmailTemplate,
 * previewEmailTemplate): every email the platform sends, with the words it is sent with, the kit's own words, and the
 * placeholders it can use (each with what it stands for); saving the operator's own words, going back to the kit's,
 * and previewing words before they are saved, in the brand, each placeholder shown by its name. Saving and going back
 * are in the audit log.
 *
 * Not here: the senders (EmailController), what may be written (App\Kit\Email\EmailTexts).
 */
final class EmailTemplatesController
{
    public function __construct(
        private readonly EmailTexts $texts,
        private readonly EmailTemplate $template,
        private readonly Senders $senders,
        private readonly Audit $audit,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['templates' => array_map($this->present(...), $this->texts->kinds())]);
    }

    public function save(Request $request, string $kind): JsonResponse
    {
        if (! $this->texts->exists($kind)) {
            return $this->unknown();
        }
        $this->texts->save($kind, $request->all());
        $this->audit->record($request->user(), 'email.template_saved', null, null, ['email' => __("emails.kind_names.{$kind}")]);

        return response()->json($this->present($kind));
    }

    public function reset(Request $request, string $kind): JsonResponse
    {
        if (! $this->texts->exists($kind)) {
            return $this->unknown();
        }
        if ($this->texts->edited($kind) !== null) {
            $this->texts->reset($kind);
            $this->audit->record($request->user(), 'email.template_reset', null, null, ['email' => __("emails.kind_names.{$kind}")]);
        }

        return response()->json($this->present($kind));
    }

    /** The email these words make, before they are saved: each placeholder but the brand shows as `[name]`. */
    public function preview(Request $request, string $kind): JsonResponse
    {
        if (! $this->texts->exists($kind)) {
            return $this->unknown();
        }
        $words = $this->texts->check($kind, $request->all());
        $vars = [];
        foreach ($this->texts->placeholders($kind) as $name) {
            $vars[$name] = "[{$name}]";
        }
        unset($vars['brand']);

        return response()->json($this->template->renderTexts($words, $vars, $words['action'] === null ? null : $this->senders->link('')));
    }

    private function present(string $kind): array
    {
        $defaults = $this->texts->defaults($kind);
        $edited = $this->texts->edited($kind);

        return [
            'kind' => $kind,
            'name' => __("emails.kind_names.{$kind}"),
            'hasButton' => $defaults['action'] !== null,
            'placeholders' => array_map(fn (string $name) => [
                'name' => $name,
                'about' => trans()->has("emails.placeholders.{$name}") ? __("emails.placeholders.{$name}") : null,
            ], $this->texts->placeholders($kind)),
            'texts' => $edited ?? $defaults,
            'defaults' => $defaults,
            'edited' => $edited !== null,
        ];
    }

    private function unknown(): JsonResponse
    {
        return response()->json(['message' => __('emails.template.unknown_kind')], 404);
    }
}
