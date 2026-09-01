<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The two logs that get read after the fact (#93, #94).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS AT ALL
 *
 * `AuditService` records what a person DID and succeeded at. This records what
 * went wrong — and the two cannot be the same table, because the most important
 * entry here is the one written when a database transaction has just been
 * thrown away. An audit row written inside that transaction dies with it. A log
 * line does not.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE REFERENCE
 *
 * Every request gets one short reference. It goes into the log line AND onto
 * the error page the user is looking at, so "something went wrong" becomes a
 * six-character code they can read down a phone line, and support can find the
 * exact stack trace instead of asking a shopkeeper to describe a 500.
 *
 * It is deliberately short and unguessable-but-not-secret: it identifies a log
 * line, it authorises nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * REDACTION IS NOT OPTIONAL
 *
 * Everything that goes out of here passes through {@see redact()}. A log file
 * is read by more people than the database ever is, is copied into tickets, and
 * is the thing most likely to be shipped to a third party for diagnosis — so a
 * password that reaches it has effectively been published.
 */
class SecurityLogger
{
    protected ?string $reference = null;

    public function __construct(protected TenantContext $tenant) {}

    /**
     * The code shown on the error page and stamped into every line this request
     * writes. Generated once, then reused — the point is that they match.
     */
    public function reference(): string
    {
        return $this->reference ??= strtoupper(Str::random(6));
    }

    /**
     * A critical exception (#93). Called from the exception handler, so the
     * stack trace still goes to the application log through Laravel's normal
     * reporting — what is added here is WHO it happened to and WHERE.
     */
    public function exception(Throwable $e, array $context = []): void
    {
        Log::channel($this->channel('security'))->error($e->getMessage(), $this->envelope([
            'exception' => $e::class,
            'file' => $e->getFile().':'.$e->getLine(),
        ] + $context));
    }

    /**
     * Something was refused (#100): a lockout, a cross-tenant probe, a denied
     * sensitive permission. Not an error — the defences worked — but the whole
     * value of a defence is knowing how often it fires.
     */
    public function refused(string $event, string $message, array $context = []): void
    {
        Log::channel($this->channel('security'))->warning($message, $this->envelope([
            'event' => $event,
        ] + $context));
    }

    /**
     * A financial write that did NOT happen (#94, #98).
     *
     * `$attempt` should carry enough to answer "what were they trying to do?" —
     * totals, line count, a customer id, the branch — and never a payment
     * credential. It is redacted regardless, because the guarantee has to hold
     * for the careless caller too.
     */
    public function financialFailure(string $operation, ?Throwable $e = null, array $attempt = []): void
    {
        Log::channel($this->channel('financial'))->error(
            sprintf('%s failed: %s', $operation, $e?->getMessage() ?? 'rolled back'),
            $this->envelope([
                'operation' => $operation,
                'exception' => $e ? $e::class : null,
                'attempt' => $this->redact($attempt),
            ]),
        );
    }

    /**
     * A financial write that DID happen but is worth a paper trail of its own —
     * a void, a return, a drawer correction. The audit table already holds it;
     * this puts it where the money log can be read end to end without joining
     * anything.
     */
    public function financialEvent(string $operation, string $message, array $context = []): void
    {
        Log::channel($this->channel('financial'))->info($message, $this->envelope([
            'operation' => $operation,
        ] + $this->redact($context)));
    }

    /**
     * Everything written here carries the same shape, so the log can be grepped
     * by tenant or by reference without knowing which call site produced it.
     */
    protected function envelope(array $context): array
    {
        $request = request();

        return array_filter([
            'ref' => $this->reference(),
            'business_id' => $this->tenant->hasBusiness() ? $this->tenant->businessId() : null,
            'user_id' => Auth::guard('web')->id(),
            'admin_id' => Auth::guard('admin')->id(),
            'ip' => $request?->ip(),
            'method' => $request?->method(),
            'route' => $request?->route()?->getName() ?? $request?->path(),
        ], fn ($value) => $value !== null) + $context;
    }

    /**
     * Replace the value of any configured key, at any depth, with a marker.
     *
     * The KEY is what is matched, not the value: a heuristic on values would
     * both miss a weak password and mangle a product name that happened to look
     * like a token. Keys are matched case-insensitively and by substring, so
     * `customer_card_number` is caught by `card_number`.
     */
    public function redact(array $data): array
    {
        $patterns = array_map('strtolower', (array) config('security.logging.redact', []));

        $walk = function (array $input) use (&$walk, $patterns): array {
            $out = [];

            foreach ($input as $key => $value) {
                $lower = strtolower((string) $key);

                foreach ($patterns as $pattern) {
                    if ($pattern !== '' && str_contains($lower, $pattern)) {
                        $out[$key] = '[redacted]';

                        continue 2;
                    }
                }

                $out[$key] = is_array($value) ? $walk($value) : $value;
            }

            return $out;
        };

        return $walk($data);
    }

    /**
     * Fall back to the application log rather than throwing: a logger that can
     * break a request is a liability, and the one time it would break it is a
     * misconfigured deploy — exactly when the logs matter most.
     */
    protected function channel(string $which): string
    {
        $name = (string) config("security.logging.{$which}_channel", $which);

        return array_key_exists($name, (array) config('logging.channels', []))
            ? $name
            : (string) config('logging.default', 'stack');
    }
}
