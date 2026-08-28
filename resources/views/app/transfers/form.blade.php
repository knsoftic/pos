{{--
    Shared transfer form (create + edit). #32

    Only drafts reach this screen — once a transfer has moved stock its lines
    are history, and the controller refuses to open it.
--}}
@props(['transfer', 'branches', 'sourceBranches', 'products', 'action', 'method' => 'POST'])

@php
    // Products carry their variants so the row can offer the right ones without
    // a round trip; variable products must name a variant, simple ones must not.
    $productOptions = $products->map(fn ($p) => [
        'id' => $p->id,
        'label' => $p->name.' · '.$p->sku,
        'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values()->all(),
    ])->values();

    $existingRows = old('items', $transfer->exists
        ? $transfer->items->map(fn ($i) => [
            'product_id' => $i->product_id,
            'variant_id' => $i->product_variant_id,
            'quantity' => (float) $i->quantity_sent,
            'notes' => $i->notes,
        ])->values()->all()
        : []);
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5"
      x-data="{
          products: @js($productOptions),
          rows: @js(array_values($existingRows)),
          addRow() { this.rows.push({ product_id: '', variant_id: '', quantity: '', notes: '' }); },
          removeRow(i) { this.rows.splice(i, 1); },
          variantsFor(productId) {
              const p = this.products.find(p => String(p.id) === String(productId));
              return p ? p.variants : [];
          },
      }"
      x-init="if (rows.length === 0) addRow()">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">Where is it going?</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="from_branch_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">From</label>
                <select id="from_branch_id" name="from_branch_id" required class="input">
                    @foreach ($sourceBranches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) old('from_branch_id', $transfer->from_branch_id) === $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-400">Only branches you can reach — the stock leaves from here.</p>
            </div>

            <div>
                <label for="to_branch_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">To</label>
                <select id="to_branch_id" name="to_branch_id" required class="input">
                    <option value="">Choose a destination…</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) old('to_branch_id', $transfer->to_branch_id) === $branch->id)>
                            {{ $branch->name }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-400">Any branch — you can send to shops you do not work at.</p>
            </div>

            <div class="md:col-span-2">
                <label for="notes" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Notes <span class="text-slate-400">(optional)</span>
                </label>
                <input id="notes" name="notes" type="text" maxlength="1000"
                       value="{{ old('notes', $transfer->notes) }}"
                       placeholder="Driver, vehicle, anything the other end should know" class="input" />
            </div>
        </div>
    </div>

    <div class="card p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold text-slate-900 dark:text-white">What is going</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Cost travels with the goods, so the receiving branch values them at what they actually cost.
                </p>
            </div>
            <button type="button" class="btn btn-secondary" @click="addRow()">
                <x-icon name="plus" class="h-4 w-4" /> Add line
            </button>
        </div>

        <div class="mt-4 space-y-3">
            <template x-for="(row, i) in rows" :key="i">
                <div class="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700 md:grid-cols-12">
                    <div class="md:col-span-5">
                        <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Product</label>
                        <select class="input" :name="`items[${i}][product_id]`" x-model="row.product_id" required>
                            <option value="">Choose…</option>
                            <template x-for="p in products" :key="p.id">
                                <option :value="p.id" x-text="p.label"></option>
                            </template>
                        </select>
                    </div>

                    <div class="md:col-span-3" x-show="variantsFor(row.product_id).length > 0" x-cloak>
                        <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Variant</label>
                        <select class="input" :name="`items[${i}][variant_id]`" x-model="row.variant_id">
                            <option value="">Choose…</option>
                            <template x-for="v in variantsFor(row.product_id)" :key="v.id">
                                <option :value="v.id" x-text="v.name"></option>
                            </template>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Quantity</label>
                        <input type="number" step="0.0001" min="0.0001" required class="input"
                               :name="`items[${i}][quantity]`" x-model="row.quantity" />
                    </div>

                    <div class="flex items-end md:col-span-2">
                        <button type="button" class="btn btn-ghost !px-2 text-rose-600 dark:text-rose-400"
                                @click="removeRow(i)" title="Remove this line">
                            <x-icon name="trash" class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </template>

            <p x-show="rows.length === 0" class="rounded-xl bg-slate-50 px-3 py-4 text-center text-sm text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                Add at least one product.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('app.transfers.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $transfer->exists ? 'Save draft' : 'Create draft' }}
        </button>
    </div>
</form>
