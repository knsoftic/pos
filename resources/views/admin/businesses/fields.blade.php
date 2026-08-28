{{--
    Business identity fields, shared by create and edit.

    Expects: $business, $statuses
--}}
<div class="card p-5">
    <h3 class="font-semibold text-slate-900 dark:text-white">Business details</h3>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="label" for="name">Business name</label>
            <input id="name" name="name" type="text" required maxlength="255"
                   value="{{ old('name', $business->name) }}" class="input" placeholder="Demo Retail Store">
            @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="slug">Slug</label>
            <input id="slug" name="slug" type="text" maxlength="255"
                   value="{{ old('slug', $business->slug) }}" class="input" placeholder="demo-retail-store">
            <p class="mt-1 text-xs text-slate-400">Leave blank to derive it from the name.</p>
            @error('slug') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="email">Contact email</label>
            <input id="email" name="email" type="email" maxlength="255"
                   value="{{ old('email', $business->email) }}" class="input" placeholder="store@example.com">
            @error('email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="phone">Contact phone</label>
            <input id="phone" name="phone" type="text" maxlength="40"
                   value="{{ old('phone', $business->phone) }}" class="input">
            @error('phone') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div class="sm:col-span-2">
            <label class="label" for="address">Address</label>
            <textarea id="address" name="address" rows="2" maxlength="500" class="input">{{ old('address', $business->address) }}</textarea>
            @error('address') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="status">Account status</label>
            <select id="status" name="status" required class="input">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $business->status) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            {{-- Suspension is enforced on the next request, not just in the UI. #130 --}}
            <p class="mt-1 text-xs text-slate-400">Suspending signs every user of this tenant out on their next request.</p>
            @error('status') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="locale">Locale</label>
            <input id="locale" name="locale" type="text" required maxlength="10"
                   value="{{ old('locale', $business->locale ?? config('app.locale')) }}" class="input" placeholder="en">
            @error('locale') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div class="sm:col-span-2">
            <label class="label" for="timezone">Timezone</label>
            <select id="timezone" name="timezone" required class="input">
                @php $selectedTz = old('timezone', $business->timezone ?? config('app.timezone', 'UTC')); @endphp
                @foreach (timezone_identifiers_list() as $tz)
                    <option value="{{ $tz }}" @selected($selectedTz === $tz)>{{ $tz }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-400">
                Drives day-boundaries for this tenant's reports and shift totals — set it to the store's real timezone.
            </p>
            @error('timezone') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
    </div>
</div>
