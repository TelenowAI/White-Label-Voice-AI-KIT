<?php

namespace App\Kit\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;

/**
 * The admin panel's audit log (SELF_HOSTED_KIT_PLAN.md §4.2): what staff did to customers, to money and to the team —
 * staff invited, joining, their role changed or removed; a customer suspended, resumed, credited, its plan or limits
 * changed, closed, viewed as; who did it and when. Entries are only ever added.
 *
 * Written by the admin controllers and App\Kit\Staff\Staff; read by Admin\AuditController. The words for each action
 * are lang/<locale>/audit.php.
 */
final class Audit
{
    public const CUSTOMER = 'customer';

    public const STAFF = 'staff';

    public function record(?User $by, string $action, ?string $subjectType = null, ?string $subjectId = null, array $details = []): AuditEvent
    {
        return AuditEvent::create(['user_id' => $by?->id, 'action' => $action, 'subject_type' => $subjectType,
            'subject_id' => $subjectId, 'details' => $details === [] ? null : $details]);
    }

    /** @return array{entries: list<array>, page: int, perPage: int, total: int} newest first */
    public function page(int $page): array
    {
        $perPage = config('kit.admin.per_page');
        $paged = AuditEvent::query()->with('user')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $customers = Workspace::query()->whereIn('id', collect($paged->items())->where('subject_type', self::CUSTOMER)->pluck('subject_id'))->pluck('name', 'id');
        $staff = User::query()->whereIn('id', collect($paged->items())->where('subject_type', self::STAFF)->pluck('subject_id'))->pluck('email', 'id');

        return [
            'entries' => array_map(fn (AuditEvent $e) => [
                'id' => $e->id,
                'at' => $e->created_at->toIso8601String(),
                'by' => $e->user === null ? null : ['id' => $e->user->id, 'name' => $e->user->name, 'email' => $e->user->email],
                'action' => $e->action,
                'label' => __("audit.actions.{$e->action}") === "audit.actions.{$e->action}" ? $e->action : __("audit.actions.{$e->action}"),
                'subject' => $e->subject_type === null ? null : [
                    'type' => $e->subject_type,
                    'id' => (string) $e->subject_id,
                    'label' => match ($e->subject_type) {
                        self::CUSTOMER => $customers[$e->subject_id] ?? null,
                        self::STAFF => $staff[$e->subject_id] ?? null,
                        default => null,
                    },
                ],
                'details' => $e->details ?? (object) [],
            ], $paged->items()),
            'page' => $paged->currentPage(),
            'perPage' => $perPage,
            'total' => $paged->total(),
        ];
    }
}
