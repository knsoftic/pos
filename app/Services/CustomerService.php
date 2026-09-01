<?php

namespace App\Services;

use App\Exceptions\LimitExceededException;
use App\Models\Customer;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer records (#39, #40, #105).
 *
 * The rules that live here rather than in a controller:
 *   QUOTA    — customers are metered (#8, #79).
 *   CODES    — allocated centrally so they stay unique per business.
 *   CREDIT   — the limit is data, but "0 means cash only" is a convention the
 *              whole app reads the same way (#40).
 *   ARCHIVE  — a customer with a statement is archived, never deleted (#104).
 *   BLOCKING — a blocked customer keeps everything and simply cannot transact.
 */
class CustomerService
{
    public function __construct(
        protected TenantContext $tenant,
        protected PlanLimitService $limits,
        protected CustomerLedgerService $ledger,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws LimitExceededException
     */
    public function create(array $data): Customer
    {
        $this->limits->assertCanCreate(LimitRegistry::CUSTOMERS);

        return DB::transaction(function () use ($data): Customer {
            $customer = new Customer([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'credit_limit' => $this->creditLimit($data),
                'notes' => $data['notes'] ?? null,
            ]);

            $customer->code = $this->allocateCode($data['code'] ?? null, $data['name']);
            $customer->is_active = (bool) ($data['is_active'] ?? true);
            $customer->save();

            // What they already owed when the shop started keeping books here —
            // posted through the ledger like everything else, so day one is part
            // of the same history as day one thousand (#152's idea, for money).
            if (filled($data['opening_balance'] ?? null) && abs((float) $data['opening_balance']) >= 0.005) {
                $this->ledger->recordOpeningBalance(
                    $customer,
                    (float) $data['opening_balance'],
                    $data['opening_balance_date'] ?? null,
                );
            }

            $this->limits->flush();
            $this->audit->log('customer.created', $customer, "Customer \"{$customer->name}\" created.");

            return $customer;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Customer $customer, array $data): Customer
    {
        $customer->fill([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'credit_limit' => $this->creditLimit($data, $customer),
            'notes' => $data['notes'] ?? null,
        ]);

        // A blank code field means "keep the one you have", never "clear it".
        if (filled($data['code'] ?? null) && $data['code'] !== $customer->code) {
            $customer->code = $this->allocateCode($data['code'], $customer->name, $customer->id);
        }

        $customer->save();

        $this->audit->log('customer.updated', $customer, "Customer \"{$customer->name}\" updated.");

        return $customer;
    }

    /**
     * Block or unblock (#105).
     *
     * Blocking is not deleting and not hiding: the record, the statement and
     * every rupee of the balance stay exactly where they were. The customer
     * simply cannot transact until someone un-blocks them, and the reason is
     * recorded so the next person knows why.
     */
    public function setActive(Customer $customer, bool $active, ?string $reason = null): Customer
    {
        $customer->is_active = $active;
        $customer->blocked_reason = $active ? null : $reason;
        $customer->save();

        $this->audit->log(
            $active ? 'customer.unblocked' : 'customer.blocked',
            $customer,
            sprintf('Customer "%s" %s.', $customer->name, $active ? 'unblocked' : 'blocked'),
            $active ? [] : ['reason' => $reason],
        );

        return $customer;
    }

    /** Only a customer with no ledger history can actually be removed (#104). */
    public function delete(Customer $customer): bool
    {
        if (! $customer->canBeDeleted()) {
            return false;
        }

        $name = $customer->name;
        $customer->delete();

        $this->limits->flush();
        $this->audit->log('customer.deleted', $customer, "Customer \"{$name}\" deleted.");

        return true;
    }

    // ------------------------------------------------------------- internals

    /**
     * NULL = no ceiling, 0 = cash only. An empty form field means "cash only",
     * not "unlimited" — the safer of the two readings, and the one a shop means
     * when they leave it blank.
     *
     * @param  array<string, mixed>  $data
     */
    protected function creditLimit(array $data, ?Customer $existing = null): ?float
    {
        /*
         | Order matters here. "Unlimited" is checked FIRST because the form
         | disables the amount field when that box is ticked — and a disabled
         | input is not submitted at all. Reading the missing amount first would
         | turn every unlimited account into a cash-only one.
         */
        if (($data['unlimited_credit'] ?? false) === true) {
            return null;
        }

        // Nothing said at all: keep what the customer already had, and start a
        // new one at cash-only.
        if (! array_key_exists('credit_limit', $data)) {
            if ($existing === null) {
                return 0.0;
            }

            return $existing->credit_limit === null ? null : (float) $existing->credit_limit;
        }

        // An empty field means "none", never "no ceiling" — the safer reading,
        // and the one a shop means when they leave it blank.
        if ($data['credit_limit'] === null || $data['credit_limit'] === '') {
            return 0.0;
        }

        return max(0, round((float) $data['credit_limit'], 2));
    }

    protected function allocateCode(?string $requested, string $nameForFallback, ?int $ignoreId = null): string
    {
        $requested = $requested !== null ? strtoupper(trim($requested)) : null;

        if ($requested !== null && $requested !== '') {
            abort_if(
                $this->codeTaken($requested, $ignoreId),
                422,
                "The code \"{$requested}\" is already used by another customer.",
            );

            return $requested;
        }

        $base = Str::upper(Str::of($nameForFallback)->slug('')->limit(6, '')) ?: 'CUST';

        do {
            $candidate = 'C-'.$base.'-'.Str::upper(Str::random(4));
        } while ($this->codeTaken($candidate, $ignoreId));

        return $candidate;
    }

    protected function codeTaken(string $code, ?int $ignoreId = null): bool
    {
        return Customer::withTrashed()
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
