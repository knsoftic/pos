{{--
    Shared purchase form (create + edit a draft). #35

    Alpine holds the lines and does the arithmetic as you type; the server does
    it again from the same rules when it saves, because a total a browser
    calculated is a total a browser could get wrong.
--}}
@props(['purchase', 'suppliers', 'branches', 'products', 'defaultBranchId', 'action', 'method' => 'POST'])

@php
    $lineRows = old('lines', $purchase->exists
        ? $purchase->items->map(fn ($item) => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'quantity_ordered' => (float) $item->quantity_ordered,
            'unit_cost' => (float) $item->unit_cost,
            'discount_amount' => (float) $item->discount_amount,
            'tax_rate' => (float) $item->tax_rate,
            'batch_number' => $item->batch_number,
            'expiry_date' => $item->expiry_date?->toDateString(),
        ])->values()->all()
        : []);

    // Everything the line picker needs, so choosing a product can fill in its
    // cost and tax without a round trip.
    $catalogue = $products->map(fn ($p) => [
        'id' => $p->id,
        'name' => $p->name,
        'sku' => $p->sku,
        'cost' => (float) $p->cost_price,
        'tax' => (float) ($p->tax_rate ?? 0),
        'batches' => (bool) $p->tracks_batches,
        'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values()->all(),
    ])->values()->all();
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5"
      x-data="{
          catalogue: @js($catalogue),
          lines: @js(array_values($lineRows)),

          blank() {
              return { id: '', product_id: '', product_variant_id: '', quantity_ordered: 1, unit_cost: 0, discount_amount: 0, tax_rate: 0, batch_number: '', expiry_date: '' };
          },
          add() { this.lines.push(this.blank()); },
          remove(i) { this.lines.splice(i, 1); },

          product(line) { return this.catalogue.find(p => String(p.id) === String(line.product_id)); },

          onProductChange(line) {
              const p = this.product(line);
              if (! p) return;
              line.unit_cost = p.cost;
              line.tax_rate = p.tax;
              line.product_variant_id = '';
          },

          lineNet(line) {
              const gross = (parseFloat(line.quantity_ordered) || 0) * (parseFloat(line.unit_cost) || 0);
              const afterDiscount = Math.max(0, gross - (parseFloat(line.discount_amount) || 0));
              return afterDiscount * (1 + ((parseFloat(line.tax_rate) || 0) / 100));
          },
          subtotal() { return this.lines.reduce((sum, l) => sum + ((parseFloat(l.quantity_ordered) || 0) * (parseFloat(l.unit_cost) || 0)), 0); },
          discounts() { return this.lines.reduce((sum, l) => sum + (parseFloat(l.discount_amount) || 0), 0); },
          total() { return this.lines.reduce((sum, l) => sum + this.lineNet(l), 0); },
          money(v) { return (v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

          init() { if (this.lines.length === 0) this.add(); },
      }">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    {{-- ----------------------------------------------------------- header --}}
    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">The order</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="supplier_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Supplier</label>
                <select id="supplier_id" name="supplier_id" required class="input">
                    <option value="">Choose…</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((int) old('supplier_id', $purchase->supplier_id) === $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
                @error('supplier_id') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="branch_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Deliver to</label>
                <select id="branch_id" name="branch_id" required class="input">
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) old('branch_id', $purchase->branch_id ?? $defaultBranchId) === $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="order_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Order date</label>
                <input id="order_date" name="order_date" type="date" required max="{{ now()->toDateString() }}"
                       value="{{ old('order_date', $purchase->order_date?->toDateString() ?? now()->toDateString()) }}"
                       class="input" />
                @error('order_date') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="expected_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Expected <span class="text-slate-400">(optional)</span>
                </label>
                <input id="expected_date" name="expected_date" type="date"
                       value="{{ old('expected_date', $purchase->expected_date?->toDateString()) }}" class="input" />
                @error('expected_date') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>

            <div class="md:col-span-2">
                <label for="supplier_invoice_no" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Supplier invoice no. <span class="text-slate-400">(optional)</span>
                </label>
                <input id="supplier_invoice_no" name="supplier_invoice_no" type="text" maxlength="60"
                       value="{{ old('supplier_invoice_no', $purchase->supplier_invoice_no) }}" class="input" />
            </div>

            <div class="md:col-span-2">
                <label for="notes" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Notes <span class="text-slate-400">(optional)</span>
                </label>
                <input id="notes" name="notes" type="text" maxlength="2000"
                       value="{{ old('notes', $purchase->notes) }}" class="input" />
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------ lines --}}
    <div class="card p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold text-slate-900 dark:text-white">What you are buying</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Costs default to the product's, and can be overridden — a supplier's price on the day is what goes
                    on the bill. Nothing is ordered or received yet.
                </p>
            </div>
            <button type="button" class="btn btn-secondary" @click="add()">
                <x-icon name="plus" class="h-4 w-4" /> Add line
            </button>
        </div>

        @error('lines') <p class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror

        <div class="mt-4 space-y-3">
            <template x-for="(line, i) in lines" :key="i">
                <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <input type="hidden" :name="`lines[${i}][id]`" :value="line.id" />

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                        <div class="md:col-span-4">
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Product</label>
                            <select class="input" :name="`lines[${i}][product_id]`" x-model="line.product_id"
                                    @change="onProductChange(line)">
                                <option value="">Choose…</option>
                                <template x-for="p in catalogue" :key="p.id">
                                    <option :value="p.id" x-text="p.name + ' · ' + p.sku"></option>
                                </template>
                            </select>
                        </div>

                        <div class="md:col-span-2" x-show="product(line) && product(line).variants.length" x-cloak>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Variant</label>
                            <select class="input" :name="`lines[${i}][product_variant_id]`" x-model="line.product_variant_id">
                                <option value="">Choose…</option>
                                <template x-for="v in (product(line) ? product(line).variants : [])" :key="v.id">
                                    <option :value="v.id" x-text="v.name"></option>
                                </template>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Quantity</label>
                            <input type="number" step="0.0001" min="0" class="input"
                                   :name="`lines[${i}][quantity_ordered]`" x-model="line.quantity_ordered" />
                        </div>

                        <div class="md:col-span-2">
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Unit cost</label>
                            <input type="number" step="0.0001" min="0" class="input"
                                   :name="`lines[${i}][unit_cost]`" x-model="line.unit_cost" />
                        </div>

                        <div class="md:col-span-1">
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Disc.</label>
                            <input type="number" step="0.01" min="0" class="input"
                                   :name="`lines[${i}][discount_amount]`" x-model="line.discount_amount" />
                        </div>

                        <div class="md:col-span-1">
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Tax %</label>
                            <input type="number" step="0.01" min="0" max="100" class="input"
                                   :name="`lines[${i}][tax_rate]`" x-model="line.tax_rate" />
                        </div>

                        {{-- Batch details only for products that track them (#34) --}}
                        <template x-if="product(line) && product(line).batches">
                            <div class="md:col-span-3">
                                <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Lot number</label>
                                <input type="text" maxlength="60" class="input"
                                       :name="`lines[${i}][batch_number]`" x-model="line.batch_number" />
                            </div>
                        </template>

                        <template x-if="product(line) && product(line).batches">
                            <div class="md:col-span-3">
                                <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Expiry</label>
                                <input type="date" class="input"
                                       :name="`lines[${i}][expiry_date]`" x-model="line.expiry_date" />
                            </div>
                        </template>

                        <div class="flex items-end justify-between gap-3 md:col-span-12">
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                Line total
                                <span class="ml-1 font-semibold tabular-nums text-slate-900 dark:text-white"
                                      x-text="money(lineNet(line))"></span>
                            </p>
                            <button type="button" class="btn btn-ghost !px-2 text-rose-600 dark:text-rose-400"
                                    @click="remove(i)" title="Remove this line">
                                <x-icon name="trash" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- ---------------------------------------------------- the totals --}}
        <dl class="mt-5 space-y-1.5 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
            <div class="flex items-baseline justify-between">
                <dt class="text-slate-500 dark:text-slate-400">Subtotal</dt>
                <dd class="tabular-nums text-slate-800 dark:text-slate-200" x-text="money(subtotal())"></dd>
            </div>
            <div class="flex items-baseline justify-between">
                <dt class="text-slate-500 dark:text-slate-400">Discounts</dt>
                <dd class="tabular-nums text-slate-800 dark:text-slate-200" x-text="'−' + money(discounts())"></dd>
            </div>
            <div class="flex items-baseline justify-between border-t border-slate-100 pt-2 dark:border-slate-800">
                <dt class="font-semibold text-slate-900 dark:text-white">Total</dt>
                <dd class="text-lg font-bold tabular-nums text-slate-900 dark:text-white" x-text="money(total())"></dd>
            </div>
        </dl>
    </div>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ $purchase->exists ? route('app.purchases.show', $purchase) : route('app.purchases.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $purchase->exists ? 'Save draft' : 'Create draft' }}
        </button>
    </div>
</form>
