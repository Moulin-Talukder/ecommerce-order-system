<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Like;
use App\Support\StrictDate;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Read-only audit trail for Super Admins.
 *
 * Shows only what is stored in audit_logs (already redacted when written):
 * the actor and subject snapshots are used as-is, and no live actor or
 * subject record is ever loaded.
 */
class AuditLogController extends Controller
{
    /** Longest explicit date range a search may cover. */
    public const MAX_RANGE_DAYS = 366;

    private const LIST_COLUMNS = ['id', 'event', 'origin', 'actor_name', 'actor_role', 'subject_type', 'subject_id', 'subject_label', 'description', 'created_at'];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AuditLog::class);

        $event = AuditEvent::tryFrom((string) $request->query('event'));
        $origin = AuditOrigin::tryFrom((string) $request->query('origin'));
        $subjectType = in_array($request->query('subject_type'), AuditLog::SUBJECT_TYPES, true) ? $request->query('subject_type') : null;
        $actors = User::query()->orderBy('name')->get(['id', 'name']);
        $actorId = $actors->firstWhere('id', (int) $request->query('actor'))?->id;
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        [$from, $to, $dateError] = $this->dateRange($request);

        $logs = AuditLog::query()
            ->when($event, fn ($query, AuditEvent $event) => $query->where('event', $event))
            ->when($origin, fn ($query, AuditOrigin $origin) => $query->where('origin', $origin))
            ->when($subjectType, fn ($query, string $type) => $query->where('subject_type', $type))
            ->when($actorId, fn ($query, int $id) => $query->where('actor_id', $id))
            ->when($from, fn ($query, Carbon $from) => $query->where('created_at', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($query, Carbon $to) => $query->where('created_at', '<=', $to->copy()->endOfDay()))
            ->when($search !== '', fn ($query) => $query->where('subject_label', 'like', Like::contains($search)))
            // An invalid date range shows the error and no rows, never a wider search.
            ->when($dateError !== null, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('id')
            ->paginate(25, self::LIST_COLUMNS)
            ->withQueryString();

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'actors' => $actors,
            'filters' => [
                'event' => $event, 'origin' => $origin, 'subject_type' => $subjectType, 'actor' => $actorId,
                'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', ''), 'search' => $search,
            ],
            'dateError' => $dateError,
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        Gate::authorize('view', $auditLog);

        $old = $this->flatten($auditLog->old_values);
        $new = $this->flatten($auditLog->new_values);

        return view('admin.audit-logs.show', [
            'log' => $auditLog,
            'changes' => array_map(
                fn (string $field) => ['field' => $field, 'old' => $old[$field] ?? null, 'new' => $new[$field] ?? null],
                array_values(array_unique([...array_keys($old), ...array_keys($new)])),
            ),
            'hasOld' => $old !== [],
            'hasNew' => $new !== [],
            'context' => $this->flatten($auditLog->context),
        ]);
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon, 2: ?string}
     */
    private function dateRange(Request $request): array
    {
        $from = StrictDate::parse($request->query('from'));
        $to = StrictDate::parse($request->query('to'));

        $error = match (true) {
            ($request->filled('from') && ! $from) || ($request->filled('to') && ! $to) => 'Enter dates as YYYY-MM-DD.',
            $from && $to && $from->greaterThan($to) => 'The start date must be on or before the end date.',
            $from && $to && $from->diffInDays($to) >= self::MAX_RANGE_DAYS => 'A date range can cover at most '.self::MAX_RANGE_DAYS.' days.',
            default => null,
        };

        return [$from, $to, $error];
    }

    /**
     * Nested values as "parent.child" => display text, for key/value tables.
     *
     * @param  array<array-key, mixed>|null  $values
     * @return array<string, string>
     */
    private function flatten(?array $values): array
    {
        $display = [];

        foreach (Arr::dot($values ?? []) as $key => $value) {
            $display[(string) $key] = match (true) {
                $value === null, $value === [] => '—',
                is_bool($value) => $value ? 'Yes' : 'No',
                default => (string) $value,
            };
        }

        return $display;
    }
}
