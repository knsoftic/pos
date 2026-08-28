<?php

namespace App\Listeners;

use App\Models\Admin;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

/**
 * Feeds every authentication event into the audit trail (#61).
 *
 * Registered explicitly as a subscriber in AppServiceProvider — method names
 * are deliberately NOT `handle`/`__invoke` so Laravel's event auto-discovery
 * doesn't register them a second time.
 *
 * SECURITY: the Failed event carries the submitted credentials, including the
 * password. Only the email is ever recorded — never the password.
 */
class AuthEventSubscriber
{
    public function __construct(protected AuditService $audit) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'onLogin']);
        $events->listen(Logout::class, [self::class, 'onLogout']);
        $events->listen(Failed::class, [self::class, 'onFailed']);
        $events->listen(Lockout::class, [self::class, 'onLockout']);
        $events->listen(PasswordReset::class, [self::class, 'onPasswordReset']);
    }

    public function onLogin(Login $event): void
    {
        $this->audit->log(
            event: 'auth.login',
            auditable: $event->user,
            description: $this->label($event->user).' logged in.',
            properties: ['guard' => $event->guard, 'remember' => (bool) $event->remember],
            actor: $event->user,
        );
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        $this->audit->log(
            event: 'auth.logout',
            auditable: $event->user,
            description: $this->label($event->user).' logged out.',
            properties: ['guard' => $event->guard],
            actor: $event->user,
        );
    }

    public function onFailed(Failed $event): void
    {
        $this->audit->log(
            event: 'auth.failed',
            auditable: $event->user,
            description: 'Failed login attempt.',
            properties: [
                'guard' => $event->guard,
                // Email only — credentials['password'] is intentionally dropped.
                'email' => $event->credentials['email'] ?? null,
            ],
            actor: $event->user,
        );
    }

    public function onLockout(Lockout $event): void
    {
        $this->audit->log(
            event: 'auth.lockout',
            description: 'Too many failed login attempts — temporarily locked out.',
            properties: ['email' => $event->request->input('email')],
        );
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->audit->log(
            event: 'auth.password_reset',
            auditable: $event->user,
            description: $this->label($event->user).' reset their password.',
            actor: $event->user,
        );
    }

    protected function label(mixed $user): string
    {
        return match (true) {
            $user instanceof Admin => 'Super admin',
            $user instanceof User => 'User',
            default => 'Account',
        };
    }
}
