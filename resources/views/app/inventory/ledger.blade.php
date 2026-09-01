<x-layouts.app :title="$product->name . ' · stock'">

    <x-flash />

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('app.inventory.index') }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to inventory
        </a>
        <span class="text-xs text-slate-400">SKU {{ $product->sku }}</span>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">

        {{-- ------------------------------------------------ per-branch stock --}}
        <div class="space-y-5">
            <div class="card p-5">
                <h3 class="font-semibold text-slate-900 dark:text-white">{{ $product->name }}</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    <span class="{{ $product->type->badgeClass() }}">{{ $product->type->label() }}</span>
                </p>

                <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                    @forelse ($byBranch as $row)
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $row['branch'] ?? 'Branch' }}</dt>
                            <dd class="text-right">
                                <span class="font-semibold tabular-nums text-slate-900 dark:text-white">
                                    {{ rtrim(rtrim(number_format($row['quantity'], 4), '0'), '.') }}
                                </span>
                                <span class="text-xs text-slate-400">{{ $product->unit?->short_name }}</span>
                                @if ($canSeeCost)
                                    <span class="block text-xs text-slate-400">worth {{ number_format($row['value'], 2) }}</span>
                                @endif
                            </dd>
                        </div>
                    @empty
                        <p class="text-slate-400">Nothing on any shelf yet.</p>
                    @endforelse
                </dl>

                @if ($product->tracks_batches && $batches->isNotEmpty())
                    <div class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <p class="mb-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                            Batches — earliest expiry leaves first
                        </p>
                        <ul class="space-y-1.5">
                            @foreach ($batches as $batch)
                                <li class="flex items-center justify-between gap-2 text-xs">
                                    <span class="truncate text-slate-600 dark:text-slate-300">
                                        {{ $batch->batch_number ?? 'No lot' }}
                                        <span class="text-slate-400">· {{ $batch->branch?->name }}</span>
                                    </span>
                                    <span class="flex shrink-0 items-center gap-2">
                                        <span class="tabular-nums text-slate-700 dark:text-slate-300">
                                            {{ rtrim(rtrim(number_format((float) $batch->quantity, 4), '0'), '.') }}
                                        </span>
                                        <span class="{{ $batch->statusBadgeClass() }}">{{ $batch->statusLabel() }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- ------------------------------------------- adjustment (#31) --}}
            @can(\App\Support\PermissionRegistry::INVENTORY_ADJUST)
                <div class="card p-5">
                    <h3 class="font-semibold text-slate-900 dark:text-white">Adjust stock</h3>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Use a positive number for stock you found, a negative one for stock you lost. The reason is
                        recorded against the movement and cannot be edited afterwards.
                    </p>

                    <form method="POST" action="{{ route('app.inventory.adjust') }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}" />

                        @if ($product->hasVariants())
                            <div>
                                <label for="variant_id" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Variant</label>
                                <select id="variant_id" name="variant_id" required class="input">
                                    <option value="">Choose…</option>
                                    @foreach ($product->variants as $variant)
                                        <option value="{{ $variant->id }}">{{ $variant->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div>
                            <label for="branch_id" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Branch</label>
                            <select id="branch_id" name="branch_id" required class="input">
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected($selectedBranch === $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="quantity" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">
                                Change by
                            </label>
                            <input id="quantity" name="quantity" type="number" step="0.0001" required
                                   value="{{ old('quantity') }}" placeholder="-2" class="input" />
                        </div>

                        {{-- Batch details, only for products that track them (#34).
                             Adding stock names a new lot; taking it away names the
                             batch it comes out of. --}}
                        @if ($product->tracks_batches)
                            <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                <p class="mb-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                                    Batch details
                                </p>

                                <div class="space-y-2">
                                    <input name="batch_number" type="text" maxlength="60"
                                           value="{{ old('batch_number') }}" placeholder="Lot number (optional)"
                                           class="input !py-2 text-sm" />

                                    <input name="expiry_date" type="date" value="{{ old('expiry_date') }}"
                                           class="input !py-2 text-sm" />

                                    @if ($batches->isNotEmpty())
                                        <select name="batch_id" class="input !py-2 text-sm">
                                            <option value="">Or take from an existing batch…</option>
                                            @foreach ($batches as $batch)
                                                <option value="{{ $batch->id }}">
                                                    {{ $batch->batch_number ?? 'No lot' }} ·
                                                    {{ rtrim(rtrim(number_format((float) $batch->quantity, 4), '0'), '.') }} left ·
                                                    {{ $batch->statusLabel() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div>
                            <label for="reason" class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Reason</label>
                            <input id="reason" name="reason" type="text" required maxlength="255"
                                   value="{{ old('reason') }}" placeholder="Damaged in transit" class="input" />
                        </div>

                        <button type="submit" class="btn btn-primary w-full">
                            <x-icon name="check" class="h-4 w-4" /> Record adjustment
                        </button>
                    </form>
                </div>
            @endcan
        </div>

        {{-- ------------------------------------------------------ the ledger --}}
        <div class="card overflow-hidden lg:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <div>
                    <h3 class="font-semibold text-slate-900 dark:text-white">Movement history</h3>
                    <p class="mt-0.5 text-xs text-slate-400">
                        Every change ever made, newest first. Nothing here is ever edited — a mistake is corrected by
                        recording its opposite.
                    </p>
                </div>

                <form method="GET" action="{{ route('app.inventory.ledger', $product) }}">
                    <select name="branch" class="input !py-1.5 text-xs" onchange="this.form.submit()">
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($selectedBranch === $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-400">
                        <tr class="border-b border-slate-100 dark:border-slate-800">
                            <th class="px-5 py-3 font-medium">When</th>
                            <th class="px-5 py-3 font-medium">What</th>
                            <th class="px-5 py-3 text-right font-medium">Change</th>
                            <th class="px-5 py-3 text-right font-medium">Balance</th>
                            <th class="px-5 py-3 font-medium">Who</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($movements as $movement)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                                    <p>{{ $movement->created_at?->format('d M Y') }}</p>
                                    <p class="text-xs text-slate-400">{{ $movement->created_at?->format('H:i') }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="{{ $movement->type->badgeClass() }}">{{ $movement->type->label() }}</span>
                                    @if ($movement->variant)
                                        <p class="mt-1 text-xs text-slate-400">{{ $movement->variant->name }}</p>
                                    @endif
                                    @if ($movement->reason)
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $movement->reason }}</p>
                                    @endif
                                    <p class="text-xs text-slate-400">{{ $movement->branch?->name }}</p>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums font-medium {{ $movement->isIncoming() ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                    {{ $movement->signedQuantity() }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-900 dark:text-white">
                                    {{ rtrim(rtrim(number_format((float) $movement->balance_after, 4), '0'), '.') }}
                                </td>
                                <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                                    {{ $movement->user?->name ?? 'System' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-slate-400">
                                    No movements yet for this product.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($movements->hasPages())
                <div class="border-t border-slate-100 px-5 py-3 dark:border-slate-800">
                    {{ $movements->links() }}
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
