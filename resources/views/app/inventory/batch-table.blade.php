{{-- Shared by both lists on the expiry screen (#34). --}}
@props(['batches', 'canSeeCost' => false, 'empty' => 'Nothing here.'])

<div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        <thead class="text-xs uppercase tracking-wide text-slate-400">
            <tr class="border-b border-slate-100 dark:border-slate-800">
                <th class="px-5 py-3 font-medium">Product</th>
                <th class="px-5 py-3 font-medium">Batch</th>
                <th class="px-5 py-3 font-medium">Branch</th>
                <th class="px-5 py-3 text-right font-medium">Quantity</th>
                @if ($canSeeCost)
                    <th class="px-5 py-3 text-right font-medium">Value</th>
                @endif
                <th class="px-5 py-3 font-medium">Expiry</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($batches as $batch)
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <td class="px-5 py-3">
                        <a href="{{ route('app.inventory.ledger', $batch->product_id) }}"
                           class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                            {{ $batch->product?->name }}
                        </a>
                        @if ($batch->variant)
                            <p class="text-xs text-slate-400">{{ $batch->variant->name }}</p>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                        {{ $batch->batch_number ?? '—' }}
                    </td>
                    <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $batch->branch?->name }}</td>
                    <td class="px-5 py-3 text-right tabular-nums font-medium text-slate-900 dark:text-white">
                        {{ rtrim(rtrim(number_format((float) $batch->quantity, 4), '0'), '.') }}
                    </td>
                    @if ($canSeeCost)
                        <td class="px-5 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">
                            {{ number_format($batch->value(), 2) }}
                        </td>
                    @endif
                    <td class="px-5 py-3">
                        <span class="{{ $batch->statusBadgeClass() }}">{{ $batch->statusLabel() }}</span>
                        @if ($batch->expiry_date)
                            <p class="mt-0.5 text-xs text-slate-400">{{ $batch->expiry_date->format('d M Y') }}</p>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $canSeeCost ? 6 : 5 }}" class="px-5 py-10 text-center text-slate-400">{{ $empty }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
