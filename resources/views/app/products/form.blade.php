{{--
    Shared product form (create + edit). #24, #25, #27, #52

    Alpine drives two things: which fields the chosen type needs, and the variant
    rows. Everything it decides is re-decided on the server — the type rules live
    in ProductService, and cost is dropped by the form request when the user may
    not see it (#52).
--}}
@props(['product', 'categories', 'brands', 'units', 'types', 'canSeeCost', 'variantsEnabled', 'batchesEnabled' => false, 'action', 'method' => 'POST'])

@php
    use App\Enums\ProductType;
    use App\Support\PermissionRegistry;

    // Existing variants, or one blank row so a new variable product has
    // something to fill in.
    $variantRows = old('variants', $product->exists && $product->hasVariants()
        ? $product->variants->map(fn ($v) => [
            'id' => $v->id,
            'name' => $v->name,
            'sku' => $v->sku,
            'barcode' => $v->barcode,
            'cost_price' => (float) $v->cost_price,
            'selling_price' => (float) $v->selling_price,
            'options' => is_array($v->options) ? $v->options : [],
            'is_active' => (bool) $v->is_active,
        ])->values()->all()
        : []);
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5" enctype="multipart/form-data"
      x-data="{
          type: '{{ old('type', $product->exists ? $product->type->value : ProductType::Standard->value) }}',
          variants: @js(array_values($variantRows)),
          addVariant() {
              this.variants.push({ id: '', name: '', sku: '', barcode: '', cost_price: '', selling_price: '', options: { Size: '', Colour: '' }, is_active: true });
          },
          removeVariant(i) { this.variants.splice(i, 1); },
      }">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    {{-- ---------------------------------------------------------- basics --}}
    <div class="card p-5">
        <h3 class="font-semibold text-slate-900 dark:text-white">What is it?</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="md:col-span-2">
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Name</label>
                <input id="name" name="name" type="text" required maxlength="180"
                       value="{{ old('name', $product->name) }}" placeholder="Cola 500ml" class="input" />
            </div>

            <div class="md:col-span-2">
                <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Type</span>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    @foreach ($types as $type)
                        @php $disabled = $type === ProductType::Variable && ! $variantsEnabled; @endphp
                        <label @class([
                            'flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 transition-colors',
                            'border-slate-200 hover:border-brand-300 dark:border-slate-700' => ! $disabled,
                            'cursor-not-allowed border-slate-100 opacity-60 dark:border-slate-800' => $disabled,
                        ])>
                            <input type="radio" name="type" value="{{ $type->value }}" x-model="type"
                                   @disabled($disabled)
                                   @checked(old('type', $product->exists ? $product->type->value : ProductType::Standard->value) === $type->value)
                                   class="mt-0.5 h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600" />
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">{{ $type->label() }}</span>
                                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ $type->description() }}</span>
                                @if ($disabled)
                                    <span class="mt-1 block text-xs font-medium text-amber-600 dark:text-amber-400">Not in your plan</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label for="category_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Category <span class="text-slate-400">(optional)</span>
                </label>
                <select id="category_id" name="category_id" class="input">
                    <option value="">Unfiled</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) old('category_id', $product->category_id) === $category->id)>
                            {{ $category->pathName() }}
                        </option>
                    @endforeach
                </select>
                @if ($categories->isEmpty())
                    {{-- An empty <select> looks exactly like a broken one, and the
                         screen that fills it is a tab on ANOTHER page — so from
                         here there was no road at all. The link is the fix. --}}
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        No categories yet.
                        @can(PermissionRegistry::CATALOG_MANAGE)
                            <a href="{{ route('app.categories.create') }}" class="text-brand-600 underline hover:no-underline dark:text-brand-400">Add one</a>
                            — you can file this product later.
                        @else
                            Ask the owner to add some.
                        @endcan
                    </p>
                @endif
            </div>

            <div>
                <label for="brand_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Brand <span class="text-slate-400">(optional)</span>
                </label>
                <select id="brand_id" name="brand_id" class="input">
                    <option value="">None</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected((int) old('brand_id', $product->brand_id) === $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </select>
                @if ($brands->isEmpty())
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        No brands yet.
                        @can(PermissionRegistry::CATALOG_MANAGE)
                            <a href="{{ route('app.brands.create') }}" class="text-brand-600 underline hover:no-underline dark:text-brand-400">Add one</a>
                            — or leave this blank, most shops do.
                        @else
                            Ask the owner to add some.
                        @endcan
                    </p>
                @endif
            </div>

            <div x-show="type !== '{{ ProductType::Service->value }}'" x-cloak>
                <label for="unit_id" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Unit <span class="text-slate-400">(optional)</span>
                </label>
                <select id="unit_id" name="unit_id" class="input">
                    <option value="">None</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected((int) old('unit_id', $product->unit_id) === $unit->id)>
                            {{ $unit->name }} ({{ $unit->short_name }})
                        </option>
                    @endforeach
                </select>
                @if ($units->isEmpty())
                    {{-- Same dead end as the two above. Leaving one of the three
                         silent would read as a bug in that one. --}}
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        No units yet.
                        @can(PermissionRegistry::CATALOG_MANAGE)
                            <a href="{{ route('app.units.create') }}" class="text-brand-600 underline hover:no-underline dark:text-brand-400">Add one</a>
                            — needed only if you sell by weight or length.
                        @else
                            Ask the owner to add some.
                        @endcan
                    </p>
                @endif
            </div>

            <div class="md:col-span-2">
                <label for="description" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Description <span class="text-slate-400">(optional)</span>
                </label>
                <textarea id="description" name="description" rows="2" maxlength="2000" class="input">{{ old('description', $product->description) }}</textarea>
            </div>

            {{-- Picture (#149). The preview is local — the file is not uploaded
                 until the form is saved. --}}
            <div class="md:col-span-2" x-data="{ preview: null }">
                <span class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Picture <span class="text-slate-400">(optional)</span>
                </span>

                <div class="flex flex-wrap items-start gap-4">
                    <span class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
                        <template x-if="preview">
                            <img :src="preview" alt="" class="h-full w-full object-cover" />
                        </template>
                        <template x-if="! preview">
                            <span>
                                @if ($product->image_path)
                                    <img src="{{ Storage::disk(config('uploads.products.disk'))->url($product->image_path) }}"
                                         alt="{{ $product->name }}" class="h-20 w-20 object-cover" />
                                @else
                                    <x-icon name="products" class="h-7 w-7 text-slate-300 dark:text-slate-600" />
                                @endif
                            </span>
                        </template>
                    </span>

                    <div class="min-w-0 flex-1 space-y-2">
                        <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                               class="input !py-2 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:file:bg-slate-700 dark:file:text-slate-200"
                               @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null" />

                        <p class="text-xs text-slate-400">
                            JPG, PNG or WebP · up to {{ round(config('uploads.products.max_kb') / 1024, 1) }} MB.
                        </p>

                        @if ($product->image_path)
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" name="remove_image" value="1"
                                       class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500 dark:border-slate-600 dark:bg-slate-800" />
                                <span class="text-xs text-slate-500 dark:text-slate-400">Remove the current picture</span>
                            </label>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------- codes & pricing --}}
    <div class="card p-5" x-show="type !== '{{ ProductType::Variable->value }}'" x-cloak>
        <h3 class="font-semibold text-slate-900 dark:text-white">Codes &amp; pricing</h3>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label for="sku" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    SKU <span class="text-slate-400">(optional)</span>
                </label>
                <input id="sku" name="sku" type="text" maxlength="60"
                       value="{{ old('sku', $product->sku) }}" placeholder="Generated if left blank" class="input uppercase" />
            </div>

            <div>
                <label for="barcode" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Barcode <span class="text-slate-400">(optional)</span>
                </label>
                <input id="barcode" name="barcode" type="text" maxlength="60"
                       value="{{ old('barcode', $product->barcode) }}" class="input" />
                <label class="mt-2 flex cursor-pointer items-center gap-2">
                    <input type="checkbox" name="generate_barcode" value="1" @checked(old('generate_barcode'))
                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        Generate one for me (in-store EAN-13)
                        {{-- Said plainly: the box above is pre-filled on an edit, so
                             "replaces" is the difference between a checkbox that
                             works and one that silently does nothing. --}}
                        <span class="block text-slate-400">Replaces whatever is in the box above. Only an EAN-13 can be printed as bars.</span>
                    </span>
                </label>
            </div>

            @if ($canSeeCost)
                <div>
                    <label for="cost_price" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Cost price</label>
                    <input id="cost_price" name="cost_price" type="number" step="0.0001" min="0"
                           value="{{ old('cost_price', $product->exists ? (float) $product->cost_price : '') }}" class="input" />
                </div>
            @endif

            <div>
                <label for="selling_price" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Selling price</label>
                <input id="selling_price" name="selling_price" type="number" step="0.01" min="0" required
                       value="{{ old('selling_price', $product->exists ? (float) $product->selling_price : '') }}" class="input" />
            </div>

            <div>
                <label for="tax_rate" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Tax % <span class="text-slate-400">(optional)</span>
                </label>
                <input id="tax_rate" name="tax_rate" type="number" step="0.01" min="0" max="100"
                       value="{{ old('tax_rate', $product->tax_rate) }}" placeholder="Business default" class="input" />
            </div>

            <div x-show="type === '{{ ProductType::Standard->value }}'" x-cloak>
                <label for="alert_quantity" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Low-stock alert at <span class="text-slate-400">(optional)</span>
                </label>
                <input id="alert_quantity" name="alert_quantity" type="number" step="0.0001" min="0"
                       value="{{ old('alert_quantity', $product->alert_quantity) }}" placeholder="Never warn" class="input" />
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------- variants --}}
    <div class="card p-5" x-show="type === '{{ ProductType::Variable->value }}'" x-cloak>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold text-slate-900 dark:text-white">Variants</h3>
                <p class="mt-0.5 text-xs text-slate-400">
                    Each one is sold and counted separately, with its own SKU and price. Leave a code blank and it
                    is generated.
                </p>
            </div>
            <button type="button" class="btn btn-secondary" @click="addVariant()">
                <x-icon name="plus" class="h-4 w-4" /> Add variant
            </button>
        </div>

        <div class="mt-4 space-y-3">
            <template x-for="(variant, i) in variants" :key="i">
                <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <input type="hidden" :name="`variants[${i}][id]`" :value="variant.id" />

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Size</label>
                            <input type="text" maxlength="60" class="input" :name="`variants[${i}][options][Size]`" x-model="variant.options.Size" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Colour</label>
                            <input type="text" maxlength="60" class="input" :name="`variants[${i}][options][Colour]`" x-model="variant.options.Colour" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">SKU</label>
                            <input type="text" maxlength="60" class="input uppercase" :name="`variants[${i}][sku]`" x-model="variant.sku" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Barcode</label>
                            <input type="text" maxlength="60" class="input" :name="`variants[${i}][barcode]`" x-model="variant.barcode" />
                        </div>

                        @if ($canSeeCost)
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Cost</label>
                                <input type="number" step="0.0001" min="0" class="input" :name="`variants[${i}][cost_price]`" x-model="variant.cost_price" />
                            </div>
                        @endif

                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500 dark:text-slate-400">Selling price</label>
                            <input type="number" step="0.01" min="0" class="input" :name="`variants[${i}][selling_price]`" x-model="variant.selling_price" />
                        </div>

                        <div class="flex items-end gap-3">
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="hidden" :name="`variants[${i}][is_active]`" value="0" />
                                <input type="checkbox" value="1" :name="`variants[${i}][is_active]`" x-model="variant.is_active"
                                       class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                                <span class="text-xs text-slate-500 dark:text-slate-400">Active</span>
                            </label>

                            <button type="button" class="btn btn-ghost !px-2 text-rose-600 dark:text-rose-400"
                                    @click="removeVariant(i)" title="Remove this variant">
                                <x-icon name="trash" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <p x-show="variants.length === 0" class="rounded-xl bg-slate-50 px-3 py-4 text-center text-sm text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                No variants yet — add at least one.
            </p>
        </div>
    </div>

    {{-- --------------------------------------------------------- status --}}
    <div class="card p-5">
        <div class="space-y-3">
            @unless ($product->exists)
                {{-- ⚠️ CREATE ONLY, and the edit form deliberately has no such
                     field. Stock is the sum of a movement ledger, not a number
                     to retype: changing it later is an adjustment, which carries
                     a reason and leaves a line somebody can read. A quantity box
                     on the edit screen would let the shelf and the ledger
                     disagree with nobody able to say why. --}}
                <div x-show="type === '{{ ProductType::Standard->value }}'" x-cloak>
                    <label for="opening_stock" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">
                        Stock on hand today <span class="text-slate-400">(optional)</span>
                    </label>
                    <input id="opening_stock" name="opening_stock" type="number" step="0.0001" min="0"
                           value="{{ old('opening_stock') }}" placeholder="0" class="input md:max-w-xs tabular-nums" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        How many you already have. It is filed as an opening movement at the cost price
                        above, so the figure has a reason behind it from day one. Leave it empty and the
                        product starts at zero — you can count it in later from its stock page.
                    </p>
                    @error('opening_stock') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
                </div>
            @endunless

            <label class="flex cursor-pointer items-start gap-3" x-show="type !== '{{ ProductType::Service->value }}'" x-cloak>
                <input type="checkbox" name="track_inventory" value="1"
                       @checked(old('track_inventory', $product->exists ? $product->track_inventory : true))
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                <span>
                    <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Keep stock for this</span>
                    <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                        Off for things you hand out but never count — a carrier bag, a display unit.
                    </span>
                </span>
            </label>

            @if ($batchesEnabled)
                <label class="flex cursor-pointer items-start gap-3" x-show="type !== '{{ ProductType::Service->value }}'" x-cloak>
                    <input type="checkbox" name="tracks_batches" value="1"
                           @checked(old('tracks_batches', $product->tracks_batches))
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                    <span>
                        <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Track batches &amp; expiry</span>
                        <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                            For perishables. Stock is then counted per delivery, and leaves by earliest expiry first —
                            not by whichever arrived first (#34).
                        </span>
                    </span>
                </label>
            @endif

            <label class="flex cursor-pointer items-start gap-3">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $product->exists ? $product->is_active : true))
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" />
                <span>
                    <span class="text-sm font-medium text-slate-800 dark:text-slate-200">Active</span>
                    <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                        An inactive product cannot be sold or purchased, but keeps all its history (#105).
                    </span>
                </span>
            </label>
        </div>
    </div>

    <div class="flex items-center justify-end gap-2">
        <a href="{{ route('app.products.index') }}" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <x-icon name="check" class="h-4 w-4" /> {{ $product->exists ? 'Save changes' : 'Create product' }}
        </button>
    </div>
</form>
