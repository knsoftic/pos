<x-layouts.app :title="'Return against ' . $purchase->reference">

    <x-flash />

    <div class="mb-5">
        <a href="{{ route('app.purchases.show', $purchase) }}" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to {{ $purchase->reference }}
        </a>
    </div>

    <form method="POST" action="{{ route('app.purchases.returns.store', $purchase) }}" class="space-y-5">
        @csrf

        <div class="card p-5">
            <h3 class="font-semibold text-slate-900 dark:text-white">
                Send goods back to {{ $purchase->supplier?->name }}
            </h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Posted immediately: stock comes off the shelf and the supplier is credited, in one go. You cannot send
                back more than arrived, and anything already returned is taken into account.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Reason</label>
                    <input id="reason" name="reason" type="text" required maxlength="255"
                           value="{{ old('reason') }}" placeholder="Three cases arrived damaged" class="input" />
                    @error('reason') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="return_date" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Date</label>
                    <input id="return_date" name="return_date" type="date" max="{{ now()->toDateString() }}"
                           value="{{ old('return_date', now()->toDateString()) }}" class="input" />
                    @error('return_date') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="notes" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Notes <span class="text-slate-400">(optional)</span>
                    </label>
                    <input id="notes" name="notes" type="text" maxlength="2000"
                           value="{{ old('notes') }}" class="input" />
                </div>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h3 class="font-semibold text-slate-900 dark:text-white">What is going back</h3>
                <p class="mt-0.5 text-xs text-slate-400">Leave a line at zero to keep it.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-400">
                        <tr class="border-b border-slate-100 dark:border-slate-800">
                            <th class="px-5 py-3 font-medium">Item</th>
                            <th class="px-5 py-3 text-right font-medium">Received</th>
                            <th class="px-5 py-3 text-right font-medium">Already back</th>
                            <th class="px-5 py-3 text-right font-medium">Can return</th>
                            <th class="px-5 py-3 text-right font-medium">Returning</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($purchase->items as $item)
                            @php $returnable = $item->returnableQuantity(); @endphp
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $item->description }}</p>
                                    <p class="text-xs text-slate-400">
                                        {{ number_format($item->effectiveUnitCost(), 2) }} per unit, all-in
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                    {{ rtrim(rtrim(number_format((float) $item->quantity_received, 4), '0'), '.') }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">
                                    {{ rtrim(rtrim(number_format($item->returnedQuantity(), 4), '0'), '.') }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                                    {{ rtrim(rtrim(number_format($returnable, 4), '0'), '.') }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if ($returnable > 0)
                                        <input type="number" step="0.0001" min="0" max="{{ $returnable }}"
                                               name="quantities[{{ $item->id }}]" placeholder="0"
                                               class="input !w-28 !py-1.5 text-right text-sm" />
                                    @else
                                        <span class="text-xs text-slate-400">Nothing left</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-slate-400">This purchase has no lines.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('app.purchases.show', $purchase) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-4 w-4" /> Record return
            </button>
        </div>
    </form>

</x-layouts.app>
