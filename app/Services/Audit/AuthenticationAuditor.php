<?php

namespace App\Services\Audit;

use App\Enums\AuditEvent;
use App\Enums\AuditOrigin;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Throwable;

/**
 * Records sign-in and sign-out activity from Laravel's auth events (and the
 * deactivated-account check in FortifyServiceProvider).
 *
 * Best-effort by design: an audit failure is logged and swallowed here, so
 * it can never block or break a login or logout. Admin business actions
 * stay fail-closed because they call {@see AuditLogger} directly.
 *
 * Privacy:
 *  - credentials are never read, except the submitted email, which is only
 *    used to look up an existing account and is never stored;
 *  - an attempt against an unknown email stores no account information,
 *    only the request IP and user agent;
 *  - the account a failed or locked attempt targeted is the subject, never
 *    the actor: whoever typed the password is not known to be its owner.
 *
 * Bound as a scoped instance so a forced logout announced by the admin
 * middleware is seen by the Logout listener in the same request.
 */
class AuthenticationAuditor
{
    private ?string $forcedLogoutReason = null;

    public function __construct(private AuditLogger $logger) {}

    /**
     * Interactive sign-in only. A session restored from the remember-me
     * cookie also fires Login, but the guard flags it as viaRemember().
     */
    public function handleLogin(Login $event): void
    {
        $guard = Auth::guard($event->guard);

        if (! $event->user instanceof User || (method_exists($guard, 'viaRemember') && $guard->viaRemember())) {
            return;
        }

        $this->record(AuditEvent::AuthLoginSucceeded, $event->user, $event->user, ['remember' => $event->remember]);
    }

    public function handleFailed(Failed $event): void
    {
        $this->record(AuditEvent::AuthLoginFailed, null, $this->account($event->user, $event->credentials[Fortify::username()] ?? null));
    }

    public function handleLockout(Lockout $event): void
    {
        $this->record(AuditEvent::AuthLoginLocked, null, $this->account(null, $event->request->input(Fortify::username())));
    }

    public function handleLogout(Logout $event): void
    {
        $reason = $this->forcedLogoutReason;
        $this->forcedLogoutReason = null;

        if (! $event->user instanceof User) {
            return;
        }

        if ($reason !== null) {
            $this->record(AuditEvent::AuthLogout, null, $event->user, ['forced' => true, 'reason' => $reason], AuditOrigin::System, 'Signed out automatically by the system');

            return;
        }

        $this->record(AuditEvent::AuthLogout, $event->user, $event->user);
    }

    /**
     * The correct password was given, but the account is deactivated.
     */
    public function deactivatedLoginAttempt(User $user): void
    {
        $this->record(AuditEvent::AuthDeactivatedLoginAttempt, null, $user);
    }

    /**
     * Mark the next logout in this request as forced by the system.
     *
     * @param  'account_deactivated'|'admin_access_revoked'  $reason
     */
    public function expectForcedLogout(string $reason): void
    {
        $this->forcedLogoutReason = $reason;
    }

    /**
     * The existing account a sign-in attempt targeted, if any. The lookup
     * matches how FortifyServiceProvider finds the account.
     */
    private function account(mixed $user, mixed $email): ?User
    {
        if ($user instanceof User) {
            return $user;
        }

        return is_string($email) && $email !== ''
            ? User::query()->where('email', Str::lower($email))->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(AuditEvent $event, ?User $actor, ?User $subject, array $context = [], AuditOrigin $origin = AuditOrigin::Admin, ?string $description = null): void
    {
        try {
            $this->logger->record($event, $actor, $subject, context: $context, origin: $origin, description: $description);
        } catch (Throwable $exception) {
            // The exception message can contain SQL bindings, so only its class is logged.
            Log::error('Could not record an authentication audit event.', [
                'event' => $event->value,
                'exception' => $exception::class,
            ]);
        }
    }
}
