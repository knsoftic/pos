<x-layouts.app :title="'Settings · '.($labels[$group] ?? 'Settings')">

    <x-flash />

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-4">
        {{-- ──────────────────────────── the tabs ─────────────────────────── --}}
        <nav class="card h-fit p-2 lg:col-span-1">
            @foreach ($groups as $key)
                <a href="{{ route('app.settings.show', $key) }}"
                   class="flex items-center justify-between gap-2 rounded-xl px-3 py-2.5 text-sm font-medium transition
                          {{ $group === $key
                                ? 'bg-brand-600 text-white'
                                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                    {{ $labels[$key] ?? Str::headline($key) }}
                </a>
            @endforeach
        </nav>

        <div class="space-y-5 lg:col-span-3">
            <div class="card p-5">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $labels[$group] ?? '' }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $descriptions[$group] ?? '' }}</p>
            </div>

            {{-- ══════════════════════════ the business ═══════════════════════ --}}
            @if ($group === 'general')
                <form method="POST" action="{{ route('app.settings.business') }}" enctype="multipart/form-data" class="card p-5">
                    @csrf @method('PUT')

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Shop name</label>
                            <input id="name" name="name" type="text" required maxlength="150"
                                   value="{{ old('name', $business->name) }}" class="input" />
                            <p class="mt-1 text-xs text-slate-400">Appears on every receipt, report and export.</p>
                            @error('name') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Email</label>
                            <input id="email" name="email" type="email" maxlength="190"
                                   value="{{ old('email', $business->email) }}" class="input" />
                            @error('email') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="phone" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Phone</label>
                            <input id="phone" name="phone" type="text" maxlength="40"
                                   value="{{ old('phone', $business->phone) }}" class="input" />
                        </div>

                        <div class="md:col-span-2">
                            <label for="address" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Address</label>
                            <textarea id="address" name="address" rows="2" maxlength="500" class="input">{{ old('address', $business->address) }}</textarea>
                        </div>

                        <div>
                            <label for="timezone" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Timezone</label>
                            <select id="timezone" name="timezone" required class="input">
                                @foreach ($timezones as $zone)
                                    <option value="{{ $zone }}" @selected(old('timezone', $business->timezone) === $zone)>{{ $zone }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-400">
                                Everything is stored in UTC and shown in this zone. Changing it never rewrites history —
                                it is {{ \App\Support\Format::dateTime(now()) }} here right now.
                            </p>
                            @error('timezone') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Logo</label>

                            @if ($business->logo_path)
                                <div class="mb-2 flex items-center gap-3 rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                                    <img src="{{ Storage::disk(config('uploads.products.disk'))->url($business->logo_path) }}"
                                         alt="{{ $business->name }}" class="h-10 w-auto rounded" />
                                    <label class="ml-auto flex cursor-pointer items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                        <input type="checkbox" name="remove_logo" value="1"
                                               class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500 dark:border-slate-600 dark:bg-slate-800" />
                                        Remove
                                    </label>
                                </div>
                            @endif

                            <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp"
                                   class="block w-full text-sm text-slate-500 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200 dark:text-slate-400 dark:file:bg-slate-800 dark:file:text-slate-200" />
                            @error('logo') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end">
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="check" class="h-4 w-4" /> Save
                        </button>
                    </div>
                </form>

            {{-- ══════════════════════════ tax rates ══════════════════════════ --}}
            @elseif ($group === 'taxes')
                @unless ($canManageTax)
                    <div class="card p-6 text-center">
                        <p class="text-slate-500 dark:text-slate-400">Tax is not included on this plan.</p>
                        <a href="{{ route('app.billing.index') }}" class="btn btn-primary mt-3">See plans</a>
                    </div>
                @else
                    <div class="card p-5">
                        <h3 class="font-semibold text-slate-900 dark:text-white">Add a rate</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Products keep the <strong>number</strong>, not a link to this list — so changing a rate
                            here changes what new lines get and never restates an invoice already printed.
                        </p>

                        <form method="POST" action="{{ route('app.tax-rates.store') }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                            @csrf
                            <div class="sm:col-span-2">
                                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Name</label>
                                <input id="name" name="name" type="text" required maxlength="80" placeholder="Standard" class="input" />
                                @error('name') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="rate" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Rate %</label>
                                <input id="rate" name="rate" type="number" step="0.001" min="0" max="100" required class="input" />
                                @error('rate') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex items-end gap-2">
                                <input type="hidden" name="is_active" value="1" />
                                <button type="submit" class="btn btn-primary w-full">
                                    <x-icon name="plus" class="h-4 w-4" /> Add
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="card overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="text-xs uppercase tracking-wide text-slate-400">
                                    <tr class="border-b border-slate-100 dark:border-slate-800">
                                        <th class="px-5 py-3 font-medium">Name</th>
                                        <th class="px-5 py-3 text-right font-medium">Rate</th>
                                        <th class="px-5 py-3 font-medium">Status</th>
                                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @forelse ($taxRates as $rate)
                                        <tr x-data="{ editing: false }" class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                            <td class="px-5 py-3">
                                                <span x-show="! editing" class="font-medium text-slate-900 dark:text-white">{{ $rate->name }}</span>

                                                <form x-show="editing" x-cloak method="POST"
                                                      action="{{ route('app.tax-rates.update', $rate) }}"
                                                      class="flex flex-wrap items-center gap-2">
                                                    @csrf @method('PUT')
                                                    <input name="name" type="text" required maxlength="80" value="{{ $rate->name }}"
                                                           class="input !w-36 !py-1.5 text-sm" />
                                                    <input name="rate" type="number" step="0.001" min="0" max="100" value="{{ (float) $rate->rate }}"
                                                           class="input !w-24 !py-1.5 text-sm" />
                                                    <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                                        <input type="checkbox" name="is_default" value="1" @checked($rate->is_default)
                                                               class="h-4 w-4 rounded border-slate-300 text-brand-600 dark:border-slate-600 dark:bg-slate-800" /> Default
                                                    </label>
                                                    <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                                        <input type="checkbox" name="is_active" value="1" @checked($rate->is_active)
                                                               class="h-4 w-4 rounded border-slate-300 text-brand-600 dark:border-slate-600 dark:bg-slate-800" /> Active
                                                    </label>
                                                    <button type="submit" class="btn btn-primary !px-3 !py-1.5 text-xs">Save</button>
                                                    <button type="button" class="btn btn-ghost !px-3 !py-1.5 text-xs" @click="editing = false">Cancel</button>
                                                </form>
                                            </td>
                                            <td class="px-5 py-3 text-right tabular-nums text-slate-700 dark:text-slate-200">
                                                {{ rtrim(rtrim(number_format((float) $rate->rate, 3), '0'), '.') }}%
                                            </td>
                                            <td class="px-5 py-3">
                                                @if ($rate->is_default)
                                                    <span class="badge-green">Default</span>
                                                @endif
                                                @unless ($rate->is_active)
                                                    <span class="badge-slate">Off</span>
                                                @endunless
                                            </td>
                                            <td class="px-5 py-3">
                                                <div class="flex items-center justify-end gap-1">
                                                    <button type="button" @click="editing = ! editing"
                                                            class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800"
                                                            title="Edit">
                                                        <x-icon name="pencil" class="h-4 w-4" />
                                                    </button>
                                                    <form method="POST" action="{{ route('app.tax-rates.destroy', $rate) }}"
                                                          onsubmit="return confirm('Remove &quot;{{ $rate->name }}&quot;? Invoices already printed keep their rate.')">
                                                        @csrf @method('DELETE')
                                                        <button type="submit"
                                                                class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10"
                                                                title="Remove">
                                                            <x-icon name="trash" class="h-4 w-4" />
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-5 py-10 text-center text-slate-400">
                                                No rates yet. Add the ones this shop charges.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endunless

            {{-- ═══════════════════════════ the knobs ═════════════════════════ --}}
            @else
                {{-- ⚠️ The reset form is a SIBLING, not a child: HTML has no nested
                     forms, and a browser silently drops the inner one — so the
                     "back to defaults" button would have posted the settings
                     instead. The Save button reaches back in with form=. --}}
                <form id="settingsForm" method="POST" action="{{ route('app.settings.update', $group) }}" class="card p-5">
                    @csrf @method('PUT')

                    <div class="space-y-5">
                        @foreach ($definitions as $key => $definition)
                            @php
                                $field = str_replace('.', '__', $key);
                                $value = old($field, $settings[$key] ?? null);
                                $isCustom = in_array($key, $customised, true);
                            @endphp

                            <div class="{{ $loop->last ? '' : 'border-b border-slate-100 pb-5 dark:border-slate-800' }}">
                                @if ($definition['type'] === 'bool')
                                    <label class="flex cursor-pointer items-start gap-3">
                                        <input type="hidden" name="{{ $field }}" value="0" />
                                        <input type="checkbox" name="{{ $field }}" value="1" @checked((bool) $value)
                                               class="mt-0.5 h-5 w-5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                                        <span>
                                            <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">
                                                {{ $definition['label'] }}
                                                @if ($isCustom) <span class="badge-amber ml-1">changed</span> @endif
                                            </span>
                                            @isset($definition['help'])
                                                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ $definition['help'] }}</span>
                                            @endisset
                                        </span>
                                    </label>
                                @else
                                    <label for="{{ $field }}" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                        {{ $definition['label'] }}
                                        @isset($definition['unit'])
                                            <span class="text-slate-400">({{ $definition['unit'] }})</span>
                                        @endisset
                                        @if ($isCustom) <span class="badge-amber ml-1">changed</span> @endif
                                    </label>

                                    @if ($definition['type'] === 'select')
                                        <select id="{{ $field }}" name="{{ $field }}" class="input md:max-w-md">
                                            @foreach ($definition['options'] as $optionValue => $optionLabel)
                                                <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>
                                                    {{ $optionLabel }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @elseif ($definition['type'] === 'list')
                                        <textarea id="{{ $field }}" name="{{ $field }}" rows="5"
                                                  class="input font-mono text-sm md:max-w-md">{{ is_array($value) ? implode("\n", $value) : $value }}</textarea>
                                    @elseif (in_array($definition['type'], ['int', 'decimal'], true))
                                        <input id="{{ $field }}" name="{{ $field }}" type="number"
                                               step="{{ $definition['type'] === 'int' ? '1' : '0.01' }}"
                                               value="{{ $value }}" class="input md:max-w-xs" />
                                    @else
                                        <input id="{{ $field }}" name="{{ $field }}" type="text"
                                               value="{{ $value }}" class="input md:max-w-md" />
                                    @endif

                                    @isset($definition['help'])
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $definition['help'] }}</p>
                                    @endisset
                                @endif

                                @error($field) <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>

                    @if ($group === 'format')
                        <div class="mt-5 rounded-xl bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800/50">
                            <span class="text-slate-500 dark:text-slate-400">Right now that reads:</span>
                            <span class="ml-2 font-medium text-slate-900 dark:text-white">{{ \App\Support\Format::money(1234567.891, true) }}</span>
                            <span class="mx-2 text-slate-300">·</span>
                            <span class="font-medium text-slate-900 dark:text-white">{{ \App\Support\Format::dateTime(now()) }}</span>
                        </div>
                    @endif

                </form>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <form method="POST" action="{{ route('app.settings.reset', $group) }}"
                          onsubmit="return confirm('Put this page back to the shipped defaults?')">
                        @csrf
                        <button type="submit" class="btn btn-ghost">
                            <x-icon name="refresh" class="h-4 w-4" /> Back to defaults
                        </button>
                    </form>

                    <button type="submit" form="settingsForm" class="btn btn-primary">
                        <x-icon name="check" class="h-4 w-4" /> Save
                    </button>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
