<?php

namespace App\Http\Requests\App;

use App\Enums\ProductType;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the product form (#24, #25, #27).
 *
 * Two things worth knowing:
 *
 *  1. AUTHORISATION SPLITS BY VERB. Creating needs `products.create`, editing
 *     needs `products.update` — the same form serves both, so the request has
 *     to ask the right question depending on which one this is.
 *  2. COST IS DROPPED, NOT REJECTED, when the user cannot see it (#52). A
 *     cashier with `products.update` but not `products.view_cost` gets a form
 *     with no cost field; posting it must leave the existing cost alone rather
 *     than failing validation or writing a zero.
 */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can($this->product() === null
            ? PermissionRegistry::PRODUCTS_CREATE
            : PermissionRegistry::PRODUCTS_UPDATE);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'type' => ['required', Rule::in(array_column(ProductType::cases(), 'value'))],

            // Existence within the tenant is re-checked by ProductService; these
            // ids only need to be plausible here.
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'unit_id' => ['nullable', 'integer'],

            'sku' => ['nullable', 'string', 'max:60'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'generate_barcode' => ['boolean'],

            'description' => ['nullable', 'string', 'max:2000'],

            /*
             | Product image (#149), guarded per #101. `image` makes the file
             | prove it decodes as a picture, `mimes` reads the real content
             | rather than the name it arrived with, and the dimension cap keeps
             | a phone photo from becoming a poster. The stored filename is
             | random, so nothing the caller sends decides where it lands.
             */
            'image' => [
                'nullable', 'image',
                'mimes:'.implode(',', config('uploads.products.image_mimes')),
                'max:'.config('uploads.products.max_kb'),
                'dimensions:max_width='.config('uploads.products.max_dimension')
                    .',max_height='.config('uploads.products.max_dimension'),
            ],
            'remove_image' => ['boolean'],

            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'track_inventory' => ['boolean'],

            /*
             | What is already on the shelf the day the product is entered.
             |
             | ⚠️ CREATE ONLY. On an edit this field does not exist, and that is
             | deliberate: stock is the sum of a movement ledger, not a number
             | you can retype. Changing it later is an adjustment, which carries
             | a reason and leaves a line. Letting an edit form overwrite the
             | balance would make the ledger and the shelf disagree with nobody
             | able to say why.
             */
            'opening_stock' => [$this->product() === null ? 'nullable' : 'prohibited', 'numeric', 'min:0', 'max:99999999'],
            'tracks_batches' => ['boolean'],
            'alert_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'is_active' => ['boolean'],

            // ---- variants (#25) ------------------------------------------
            'variants' => ['array', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['nullable', 'string', 'max:120'],
            'variants.*.sku' => ['nullable', 'string', 'max:60'],
            'variants.*.barcode' => ['nullable', 'string', 'max:60'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.selling_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.alert_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.is_active' => ['boolean'],
            'variants.*.options' => ['array', 'max:10'],
            'variants.*.options.*' => ['nullable', 'string', 'max:60'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'track_inventory' => $this->boolean('track_inventory'),
            'tracks_batches' => $this->boolean('tracks_batches'),
            'generate_barcode' => $this->boolean('generate_barcode'),
            'remove_image' => $this->boolean('remove_image'),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            // A variable product with no variants is not a variable product —
            // and would leave the POS with nothing to sell.
            if ($this->input('type') === ProductType::Variable->value && $this->variantRows() === []) {
                $validator->errors()->add('variants', 'A variable product needs at least one variant.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'image.image' => 'That file is not a picture.',
            'image.mimes' => 'Use a JPG, PNG or WebP image.',
            'image.max' => 'That image is larger than '.round(config('uploads.products.max_kb') / 1024, 1).' MB.',
            'image.dimensions' => 'That image is bigger than '.config('uploads.products.max_dimension').' pixels on a side.',
        ];
    }

    /**
     * The shape {@see ProductService} expects.
     *
     * @return array<string, mixed>
     */
    public function productAttributes(?Product $existing = null): array
    {
        $data = [
            'name' => $this->string('name')->toString(),
            'type' => $this->string('type')->toString(),
            'category_id' => $this->input('category_id') ?: null,
            'brand_id' => $this->input('brand_id') ?: null,
            'unit_id' => $this->input('unit_id') ?: null,
            'sku' => $this->input('sku') ?: null,
            'description' => $this->input('description') ?: null,
            'selling_price' => $this->input('selling_price'),
            'tax_rate' => $this->input('tax_rate'),
            'track_inventory' => $this->boolean('track_inventory'),
            'tracks_batches' => $this->boolean('tracks_batches'),
            'alert_quantity' => $this->input('alert_quantity'),
            'is_active' => $this->boolean('is_active'),
            'generate_barcode' => $this->boolean('generate_barcode'),

            // Only meaningful on create; the rules prohibit it on an edit.
            'opening_stock' => (float) ($this->input('opening_stock') ?: 0),
        ];

        if ($this->hasFile('image')) {
            $data['image'] = $this->file('image');
        }

        if ($this->boolean('remove_image')) {
            $data['remove_image'] = true;
        }

        if ($this->has('barcode')) {
            $data['barcode'] = $this->input('barcode') ?: null;
        }

        // #52 — only a user who may SEE cost may CHANGE it.
        if ($this->canSeeCost()) {
            $data['cost_price'] = $this->input('cost_price') ?? 0;
        } elseif ($existing !== null) {
            $data['cost_price'] = $existing->cost_price;
        }

        return $data;
    }

    /**
     * Variant rows, cleaned of the blank template row the form always renders.
     *
     * @return list<array<string, mixed>>
     */
    public function variantRows(): array
    {
        if ($this->string('type')->toString() !== ProductType::Variable->value) {
            return [];
        }

        $rows = [];

        foreach ((array) $this->input('variants', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $options = array_filter(
                (array) ($row['options'] ?? []),
                fn ($value) => is_string($value) && trim($value) !== '',
            );

            // A row with no options and no name is the empty template.
            if ($options === [] && blank($row['name'] ?? null)) {
                continue;
            }

            if (! $this->canSeeCost()) {
                unset($row['cost_price']);
            }

            $row['options'] = $options;
            $rows[] = $row;
        }

        return $rows;
    }

    protected function canSeeCost(): bool
    {
        return (bool) $this->user()?->can(PermissionRegistry::PRODUCTS_VIEW_COST);
    }

    public function product(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }
}
