<x-layouts.admin :title="'Settings · '.($labels[$group] ?? '')">

    <x-flash />

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-4">
        <nav class="card h-fit p-2 lg:col-span-1">
            @foreach ($labels as $key => $label)
                <a href="{{ route('admin.settings.show', $key) }}"
                   class="block rounded-xl px-3 py-2.5 text-sm font-medium transition
                          {{ $group === $key
                                ? 'bg-brand-600 text-white'
                                : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                    {{ $label }}
                </a>
            @endforeach
        </nav>

        <div class="space-y-5 lg:col-span-3">
            <div class="card p-5">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $labels[$group] }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $descriptions[$group] ?? '' }}</p>
            </div>

            @if ($group === 'maintenance' && config('platform.maintenance'))
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 dark:border-rose-500/30 dark:bg-rose-500/10">
                    <p class="font-semibold text-rose-800 dark:text-rose-300">Maintenance mode is ON.</p>
                    <p class="mt-1 text-sm text-rose-700 dark:text-rose-300/80">
                        Every shop's workspace and the public site are closed right now. This panel stays open —
                        it is how you turn it off.
                    </p>
                </div>
            @endif

            {{-- ═════════════════════════ the operator's mark ════════════════ --}}
            @if ($group === 'branding')
                <form method="POST" action="{{ route('admin.settings.logo') }}" enctype="multipart/form-data" class="card p-5">
                    @csrf @method('PUT')

                    <h3 class="font-semibold text-slate-900 dark:text-white">Logo</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Replaces the built-in mark on the login screen, the admin panel and the public site.
                        Leave it empty and the drawn mark is used — it is geometry rather than an image, so it
                        renders in places a file never reaches.
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-4">
                        <span class="flex h-16 w-16 items-center justify-center rounded-xl bg-slate-50 dark:bg-slate-800/50">
                            <x-brand.mark class="h-12 w-12" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <input type="file" name="logo" accept=".jpg,.jpeg,.png,.webp"
                                   class="block w-full text-sm text-slate-500 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200 dark:text-slate-400 dark:file:bg-slate-800 dark:file:text-slate-200" />
                            @error('logo') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($logo)
                                <button type="submit" name="remove_logo" value="1" class="btn btn-ghost">
                                    <x-icon name="trash" class="h-4 w-4" /> Remove
                                </button>
                            @endif
                            <button type="submit" class="btn btn-secondary">
                                <x-icon name="check" class="h-4 w-4" /> Upload
                            </button>
                        </div>
                    </div>
                </form>
            @endif

            {{-- ═══════════════════════════ the knobs ════════════════════════ --}}
            <form id="platformForm" method="POST" action="{{ route('admin.settings.update', $group) }}" class="card p-5">
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

                                @if ($definition['type'] === 'text')
                                    <textarea id="{{ $field }}" name="{{ $field }}" rows="3" class="input md:max-w-lg">{{ $value }}</textarea>
                                @elseif (in_array($definition['type'], ['int', 'decimal'], true))
                                    <input id="{{ $field }}" name="{{ $field }}" type="number"
                                           step="{{ $definition['type'] === 'int' ? '1' : '0.01' }}"
                                           value="{{ $value }}" class="input md:max-w-xs" />
                                @else
                                    <input id="{{ $field }}" name="{{ $field }}" type="text"
                                           value="{{ $value }}" class="input md:max-w-lg" />
                                @endif

                                @isset($definition['help'])
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $definition['help'] }}</p>
                                @endisset
                            @endif

                            @error($field) <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </form>

            {{-- Sibling forms, never nested: a browser drops an inner <form>. --}}
            <div class="flex flex-wrap items-center justify-end gap-2">
                <form method="POST" action="{{ route('admin.settings.reset', $group) }}"
                      onsubmit="return confirm('Put this page back to the shipped defaults?')">
                    @csrf
                    <button type="submit" class="btn btn-ghost">
                        <x-icon name="refresh" class="h-4 w-4" /> Back to defaults
                    </button>
                </form>

                <button type="submit" form="platformForm" class="btn btn-primary">
                    <x-icon name="check" class="h-4 w-4" /> Save
                </button>
            </div>
        </div>
    </div>

</x-layouts.admin>
