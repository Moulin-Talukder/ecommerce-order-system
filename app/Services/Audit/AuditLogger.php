<?php

namespace App\Services\Audit;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Writes audit records. Every value is passed through {@see AuditRedactor}
 * first; nothing reaches audit_logs any other way.
 *
 * Transactions: call this inside the business transaction. The single
 * INSERT runs on the caller's connection, so it commits or rolls back with
 * the business change and never commits anything on its own. Database
 * errors are not caught: a failed audit write fails the caller's action.
 */
class AuditLogger
{
    public function __construct(private AuditRedactor $redactor) {}

    /**
     * @param  array<array-key, mixed>  $old  Attribute values before the change (allowlisted per subject type).
     * @param  array<array-key, mixed>  $new  Attribute values after the change (allowlisted per subject type).
     * @param  array<array-key, mixed>  $context  Extra details (e.g. a related order id); secrets dropped, personal data masked.
     *
     * @throws InvalidArgumentException for an unknown origin or a model that is not an audit subject
     */
    public function record(
        AuditEvent $event,
        ?User $actor,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $context = [],
        AuditOrigin|string $origin = AuditOrigin::Admin,
        ?string $description = null,
    ): AuditLog {
        $origin = $origin instanceof AuditOrigin
            ? $origin
            : (AuditOrigin::tryFrom($origin) ?? throw new InvalidArgumentException("Unknown audit origin [{$origin}]."));

        $subjectType = $subject ? AuditLog::subjectTypeFor($subject) : null;
        [$ipAddress, $userAgent] = $this->requestDetails($origin);

        $log = new AuditLog;
        $log->forceFill([
            'event' => $event,
            'origin' => $origin,
            'actor_id' => $actor?->id,
            'actor_name' => $this->redactor->truncate($actor?->name, 255),
            'actor_email' => $this->redactor->truncate($actor?->email, 255),
            'actor_role' => $actor?->role->value,
            'subject_type' => $subjectType,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject ? $this->redactor->subjectLabel($subjectType, $subject) : null,
            'description' => $this->redactor->truncate(filled($description) ? $description : $event->label()),
            'old_values' => $this->redactor->values($subjectType, $old),
            'new_values' => $this->redactor->values($subjectType, $new),
            'context' => $this->redactor->context($context),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ])->save();

        return $log;
    }

    /**
     * IP address and user agent of the HTTP request being handled, if any.
     * Artisan commands and queue workers have no real client, so nothing is
     * captured there (or for the cli origin at all).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function requestDetails(AuditOrigin $origin): array
    {
        if ($origin === AuditOrigin::Cli || ! app()->bound('request') || (app()->runningInConsole() && ! app()->runningUnitTests())) {
            return [null, null];
        }

        $request = app('request');

        return [$this->redactor->truncate($request->ip(), 45), $this->redactor->truncate($request->userAgent(), 512)];
    }
}
