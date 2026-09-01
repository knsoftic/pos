<?php

namespace App\Services;

use App\Exceptions\LimitExceededException;
use App\Models\Supplier;
use App\Support\LimitRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Supplier records (#38).
 *
 * The same shape as {@see CustomerService} — quota, central code allocation,
 * archive-not-delete, block-without-losing-anything — minus the credit limit,
 * because a supplier's terms describe how long the BUSINESS may take to pay,
 * not how much it may owe.
 */
class SupplierService
{
    public function __construct(
        protected TenantContext $tenant,
        protected PlanLimitService $limits,
        protected SupplierLedgerService $ledger,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws LimitExceededException
     */
    public function create(array $data): Supplier
    {
        $this->limits->assertCanCreate(LimitRegistry::SUPPLIERS);

        return DB::transaction(function () use ($data): Supplier {
            $supplier = new Supplier([
                'name' => $data['name'],
                'contact_person' => $data['contact_person'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'payment_terms_days' => $this->terms($data['payment_terms_days'] ?? null),
                'notes' => $data['notes'] ?? null,
            ]);

            $supplier->code = $this->allocateCode($data['code'] ?? null, $data['name']);
            $supplier->is_active = (bool) ($data['is_active'] ?? true);
            $supplier->save();

            // What was already owed to them on day one, through the ledger.
            if (filled($data['opening_balance'] ?? null) && abs((float) $data['opening_balance']) >= 0.005) {
                $this->ledger->recordOpeningBalance(
                    $supplier,
                    (float) $data['opening_balance'],
                    $data['opening_balance_date'] ?? null,
                );
            }

            $this->limits->flush();
            $this->audit->log('supplier.created', $supplier, "Supplier \"{$supplier->name}\" created.");

            return $supplier;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->fill([
            'name' => $data['name'],
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'payment_terms_days' => $this->terms($data['payment_terms_days'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        if (filled($data['code'] ?? null) && $data['code'] !== $supplier->code) {
            $supplier->code = $this->allocateCode($data['code'], $supplier->name, $supplier->id);
        }

        $supplier->save();

        $this->audit->log('supplier.updated', $supplier, "Supplier \"{$supplier->name}\" updated.");

        return $supplier;
    }

    public function setActive(Supplier $supplier, bool $active, ?string $reason = null): Supplier
    {
        $supplier->is_active = $active;
        $supplier->blocked_reason = $active ? null : $reason;
        $supplier->save();

        $this->audit->log(
            $active ? 'supplier.unblocked' : 'supplier.blocked',
            $supplier,
            sprintf('Supplier "%s" %s.', $supplier->name, $active ? 'unblocked' : 'blocked'),
            $active ? [] : ['reason' => $reason],
        );

        return $supplier;
    }

    public function delete(Supplier $supplier): bool
    {
        if (! $supplier->canBeDeleted()) {
            return false;
        }

        $name = $supplier->name;
        $supplier->delete();

        $this->limits->flush();
        $this->audit->log('supplier.deleted', $supplier, "Supplier \"{$name}\" deleted.");

        return true;
    }

    // ------------------------------------------------------------- internals

    /** Blank means "no agreed terms", which is different from "due immediately". */
    protected function terms(mixed $days): ?int
    {
        if ($days === null || $days === '') {
            return null;
        }

        return max(0, (int) $days);
    }

    protected function allocateCode(?string $requested, string $nameForFallback, ?int $ignoreId = null): string
    {
        $requested = $requested !== null ? strtoupper(trim($requested)) : null;

        if ($requested !== null && $requested !== '') {
            abort_if(
                $this->codeTaken($requested, $ignoreId),
                422,
                "The code \"{$requested}\" is already used by another supplier.",
            );

            return $requested;
        }

        $base = Str::upper(Str::of($nameForFallback)->slug('')->limit(6, '')) ?: 'SUPP';

        do {
            $candidate = 'S-'.$base.'-'.Str::upper(Str::random(4));
        } while ($this->codeTaken($candidate, $ignoreId));

        return $candidate;
    }

    protected function codeTaken(string $code, ?int $ignoreId = null): bool
    {
        return Supplier::withTrashed()
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
