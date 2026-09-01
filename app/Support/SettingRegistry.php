<?php

namespace App\Support;

/**
 * Every setting a shop may change (#57, #190).
 *
 * ================= THE CONTRACT =================
 * A setting's key IS the config key it overrides. `pos.cash_rounding` here
 * overlays `config('pos.cash_rounding')`, so the hundred places that already
 * read that config keep working unchanged and simply start seeing the shop's
 * value. Nothing in the application needs to know a settings table exists.
 *
 * That is the whole design. The alternative — a `settings()` helper called from
 * every call site — would mean touching every file that reads a knob and would
 * leave two ways to ask the same question, one of which is wrong.
 *
 * ================= WHAT BELONGS HERE, AND WHAT DOES NOT =================
 * Settings are things about how the shop OPERATES. Facts about the business
 * itself — its name, address, phone, logo, timezone — are columns on
 * `businesses` and are edited as the business record, not as settings. Putting
 * them here as well would give every one of them two homes and no answer to
 * which one wins.
 *
 * ⚠️ `rules` is not decoration: it is the only validation these values get.
 * A setting is written by a form and then read by the sale engine, the till and
 * the receipt, so a bad value here surfaces as a broken checkout, far from the
 * screen that caused it.
 */
class SettingRegistry
{
    /**
     * @return array<string, array{group: string, label: string, help?: string,
     *                             type: string, options?: array<string, string>,
     *                             rules: list<string>, feature?: string, unit?: string}>
     */
    public static function all(): array
    {
        return [
            // ================================================== sales (#57)
            'pos.invoice.prefix' => [
                'group' => 'sales',
                'label' => 'Invoice prefix',
                'help' => 'The letters in front of every invoice number.',
                'type' => 'string',
                'rules' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-]+$/'],
            ],
            'pos.invoice.format' => [
                'group' => 'sales',
                'label' => 'Invoice number format',
                'help' => '{PREFIX}, {YYYY}, {YY}, {MM}, {DD}, {BRANCH} and {SEQ:n} for the running number.',
                'type' => 'string',
                'rules' => ['required', 'string', 'max:60'],
            ],
            'pos.invoice.sequence_scope' => [
                'group' => 'sales',
                'label' => 'Numbering restarts',
                'help' => 'One unbroken sequence is what most tax authorities expect to see.',
                'type' => 'select',
                'options' => [
                    'business' => 'Never — one sequence for the business',
                    'branch' => 'Per branch',
                    'monthly' => 'Every month',
                ],
                'rules' => ['required', 'in:business,branch,monthly'],
            ],
            'pos.cash_rounding' => [
                'group' => 'sales',
                'label' => 'Round cash totals to',
                'help' => 'The smallest coin still in circulation. 0 turns rounding off.',
                'type' => 'decimal',
                'rules' => ['required', 'numeric', 'min:0', 'max:100'],
            ],
            'pos.allow_walk_in' => [
                'group' => 'sales',
                'label' => 'Allow walk-in sales',
                'help' => 'Off means every sale must be attached to a customer.',
                'type' => 'bool',
                'rules' => ['boolean'],
            ],
            'pos.require_cash_session' => [
                'group' => 'sales',
                'label' => 'Require an open till',
                'help' => 'On, nothing can be sold until somebody has opened the drawer — the only way a cash-up can balance.',
                'type' => 'bool',
                'rules' => ['boolean'],
                'feature' => FeatureRegistry::ACCOUNTING_CASH_REGISTER,
            ],
            'pos.hold_expiry_hours' => [
                'group' => 'sales',
                'label' => 'Held sales expire after',
                'type' => 'int',
                'unit' => 'hours',
                'rules' => ['required', 'integer', 'min:1', 'max:720'],
            ],

            // ------------------------------------------- discounts (#60, #141)
            'sales.allow_line_discount' => [
                'group' => 'sales',
                'label' => 'Allow discount on a line',
                'type' => 'bool',
                'rules' => ['boolean'],
                'feature' => FeatureRegistry::SALES_DISCOUNTS,
            ],
            'sales.allow_invoice_discount' => [
                'group' => 'sales',
                'label' => 'Allow discount on the whole sale',
                'type' => 'bool',
                'rules' => ['boolean'],
                'feature' => FeatureRegistry::SALES_DISCOUNTS,
            ],
            'sales.max_discount_percent' => [
                'group' => 'sales',
                'label' => 'Most anyone may discount',
                'help' => 'A ceiling on what a cashier can give away without a manager. 100 removes the limit.',
                'type' => 'decimal',
                'unit' => '%',
                'rules' => ['required', 'numeric', 'min:0', 'max:100'],
                'feature' => FeatureRegistry::SALES_DISCOUNTS,
            ],

            // ============================================== inventory (#57)
            'inventory.allow_negative_stock' => [
                'group' => 'inventory',
                'label' => 'Allow selling below zero',
                'help' => 'On, the till will sell what the system says is not there. Useful where the shelf is ahead of the paperwork; dangerous everywhere else.',
                'type' => 'bool',
                'rules' => ['boolean'],
            ],
            'inventory.default_alert_quantity' => [
                'group' => 'inventory',
                'label' => 'Default low-stock alert at',
                'help' => 'Used for products that do not set their own. Blank means a product is never low unless it says so.',
                'type' => 'decimal',
                'rules' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            ],
            'inventory.expiry_warning_days' => [
                'group' => 'inventory',
                'label' => 'Warn about expiry',
                'type' => 'int',
                'unit' => 'days ahead',
                'rules' => ['required', 'integer', 'min:1', 'max:3650'],
                'feature' => FeatureRegistry::INVENTORY_EXPIRY_TRACKING,
            ],

            // ================================================ receipt (#57)
            'pos.receipt.width' => [
                'group' => 'receipt',
                'label' => 'Paper',
                'type' => 'select',
                'options' => ['58mm' => '58 mm roll', '80mm' => '80 mm roll', 'a4' => 'A4 sheet'],
                'rules' => ['required', 'in:58mm,80mm,a4'],
            ],
            'pos.receipt.header' => [
                'group' => 'receipt',
                'label' => 'Line above the shop name',
                'help' => 'Optional. A slogan, a branch note, anything.',
                'type' => 'string',
                'rules' => ['nullable', 'string', 'max:120'],
                'feature' => FeatureRegistry::SETTINGS_CUSTOM_INVOICE,
            ],
            'pos.receipt.footer' => [
                'group' => 'receipt',
                'label' => 'Line at the bottom',
                'type' => 'string',
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'pos.receipt.tax_number' => [
                'group' => 'receipt',
                'label' => 'Tax registration number',
                'help' => 'Printed under the shop name where the law asks for it.',
                'type' => 'string',
                'rules' => ['nullable', 'string', 'max:60'],
            ],
            'pos.receipt.show_logo' => [
                'group' => 'receipt',
                'label' => 'Print the logo',
                'type' => 'bool',
                'rules' => ['boolean'],
                'feature' => FeatureRegistry::SETTINGS_CUSTOM_LOGO,
            ],
            'pos.receipt.show_tax_breakdown' => [
                'group' => 'receipt',
                'label' => 'Show the tax breakdown',
                'type' => 'bool',
                'rules' => ['boolean'],
            ],
            'pos.receipt.show_qr' => [
                'group' => 'receipt',
                'label' => 'Print a QR code',
                'help' => 'Encodes the invoice number, date and total, so a receipt can be checked without typing anything.',
                'type' => 'bool',
                'rules' => ['boolean'],
            ],
            'pos.receipt.auto_print' => [
                'group' => 'receipt',
                'label' => 'Open the print dialog automatically',
                'type' => 'bool',
                'rules' => ['boolean'],
            ],

            // ================================================ payment (#57)
            'pos.payment_methods' => [
                'group' => 'payment',
                'label' => 'Methods the till accepts',
                'help' => 'One per line. `credit` is special — it charges the customer\'s account instead of taking money.',
                'type' => 'list',
                'rules' => ['required', 'array', 'min:1'],
            ],
            'pos.payment_qr_path' => [
                'group' => 'payment',
                'label' => 'Payment QR',
                'type' => 'string',
                'hidden' => true,
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'pos.cash_methods' => [
                'group' => 'payment',
                'label' => 'Which of those go in the drawer',
                'help' => 'Only these count towards what the till should hold at close.',
                'type' => 'list',
                'rules' => ['required', 'array', 'min:1'],
            ],

            // ================================= currency & formatting (#58, #155–157)
            'format.currency_code' => [
                'group' => 'format',
                'label' => 'Currency code',
                'type' => 'string',
                'rules' => ['required', 'string', 'size:3', 'alpha'],
            ],
            'format.currency_symbol' => [
                'group' => 'format',
                'label' => 'Symbol',
                'type' => 'string',
                'rules' => ['required', 'string', 'max:6'],
            ],
            'format.currency_position' => [
                'group' => 'format',
                'label' => 'Symbol goes',
                'type' => 'select',
                'options' => ['before' => 'Before the amount', 'after' => 'After the amount'],
                'rules' => ['required', 'in:before,after'],
            ],
            'format.decimals' => [
                'group' => 'format',
                'label' => 'Decimal places',
                'help' => 'Two for most currencies; zero where there are no coins left.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:0', 'max:4'],
            ],
            'format.thousands_separator' => [
                'group' => 'format',
                'label' => 'Thousands separator',
                'type' => 'select',
                'options' => [',' => 'Comma — 1,234.50', '.' => 'Full stop — 1.234,50', ' ' => 'Space — 1 234.50', '' => 'None — 1234.50'],
                'rules' => ['present', 'string', 'max:1'],
            ],
            'format.decimal_separator' => [
                'group' => 'format',
                'label' => 'Decimal separator',
                'type' => 'select',
                'options' => ['.' => 'Full stop', ',' => 'Comma'],
                'rules' => ['required', 'in:.,,'],
            ],
            'format.date' => [
                'group' => 'format',
                'label' => 'Dates look like',
                'type' => 'select',
                'options' => [
                    'd M Y' => '01 Sep 2026',
                    'd/m/Y' => '01/09/2026',
                    'm/d/Y' => '09/01/2026',
                    'Y-m-d' => '2026-09-01',
                    'd-m-Y' => '01-09-2026',
                ],
                'rules' => ['required', 'string', 'max:20'],
            ],
            'format.time' => [
                'group' => 'format',
                'label' => 'Times look like',
                'type' => 'select',
                'options' => ['H:i' => '14:30', 'h:i A' => '02:30 PM'],
                'rules' => ['required', 'string', 'max:20'],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function groupLabels(): array
    {
        return [
            'sales' => 'Sales & invoicing',
            'inventory' => 'Inventory',
            'receipt' => 'Receipt',
            'payment' => 'Payment methods',
            'format' => 'Currency & formats',
        ];
    }

    /** @return array<string, string> */
    public static function groupDescriptions(): array
    {
        return [
            'sales' => 'How sales are numbered, rounded and discounted.',
            'inventory' => 'What the shelf is allowed to do, and when to warn about it.',
            'receipt' => 'What the customer walks away with.',
            'payment' => 'What the till may take, and what lands in the drawer.',
            'format' => 'How money, dates and numbers are written everywhere in the system.',
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array{group: string, label: string, type: string, rules: list<string>, help?: string, options?: array<string, string>, feature?: string, unit?: string} */
    public static function definition(string $key): array
    {
        $all = self::all();

        abort_unless(isset($all[$key]), 404, "No such setting: {$key}.");

        return $all[$key];
    }

    /**
     * The settings in one group.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function group(string $group): array
    {
        return array_filter(self::all(), fn (array $s) => $s['group'] === $group);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
