<?php

namespace App\Support;

/**
 * Every switch the OPERATOR may change (#110, #111, #160).
 *
 * Same contract as {@see SettingRegistry}: the key is the config key it
 * overrides, `rules` is the only validation these values get, and only what has
 * actually been changed is stored.
 *
 * ================= WHAT IS DELIBERATELY NOT HERE =================
 * Anything a SHOP decides. A tenant's currency, receipt and discount ceiling
 * live in the other registry, per business. If a knob appeared in both, one of
 * them would win and nobody could say which.
 *
 * ⚠️ Branding is the reason `config/brand.php` insists nothing hard-codes the
 * company name. A white-label deployment changes these seven fields and the
 * login screen, the emails, the public site and the PDF footers all follow —
 * which only works because no Blade file ever wrote "KN Softic" as a literal.
 */
class PlatformSettingRegistry
{
    /**
     * @return array<string, array{group: string, label: string, help?: string,
     *                             type: string, options?: array<string, string>,
     *                             rules: list<string>, unit?: string}>
     */
    public static function all(): array
    {
        return [
            // ================================================ branding (#111)
            'brand.name' => [
                'group' => 'branding',
                'label' => 'Company name',
                'help' => 'Who publishes this software. Appears on the login screen, emails and the public site — never inside a shop\'s workspace, which belongs to the shop.',
                'type' => 'string',
                'rules' => ['required', 'string', 'max:60'],
            ],
            'brand.product' => [
                'group' => 'branding',
                'label' => 'Product name',
                'help' => 'The software itself, as distinct from the company that makes it.',
                'type' => 'string',
                'rules' => ['required', 'string', 'max:60'],
            ],
            'brand.legal_name' => [
                'group' => 'branding',
                'label' => 'Legal entity',
                'help' => 'Used where a formal name is required — invoices, terms, legal footers.',
                'type' => 'string',
                'rules' => ['required', 'string', 'max:120'],
            ],
            'brand.tagline' => [
                'group' => 'branding',
                'label' => 'Tagline',
                'type' => 'string',
                'rules' => ['nullable', 'string', 'max:120'],
            ],
            'brand.description' => [
                'group' => 'branding',
                'label' => 'One-line description',
                'help' => 'The login screen and the page meta description.',
                'type' => 'text',
                'rules' => ['nullable', 'string', 'max:300'],
            ],
            'brand.support_email' => [
                'group' => 'branding',
                'label' => 'Support email',
                'type' => 'string',
                'rules' => ['nullable', 'email', 'max:190'],
            ],
            'brand.support_phone' => [
                'group' => 'branding',
                'label' => 'Support phone',
                'type' => 'string',
                'rules' => ['nullable', 'string', 'max:40'],
            ],
            /*
            | The uploaded mark. `hidden` keeps it out of the generic form —
            | a file is not a text box — while still going through the same
            | validation, storage and "back to defaults" as everything else.
            */
            'brand.logo_path' => [
                'group' => 'branding',
                'label' => 'Logo',
                'type' => 'string',
                'hidden' => true,
                'rules' => ['nullable', 'string', 'max:255'],
            ],
            'brand.website' => [
                'group' => 'branding',
                'label' => 'Website',
                'type' => 'string',
                'rules' => ['nullable', 'url', 'max:190'],
            ],

            // ============================================= registration (#110)
            'platform.registration_open' => [
                'group' => 'signup',
                'label' => 'Anyone may sign up',
                'help' => 'Off, the public site shows the plans but not a create-account form. Existing shops are unaffected either way.',
                'type' => 'bool',
                'rules' => ['boolean'],
            ],
            'subscription.trial_days' => [
                'group' => 'signup',
                'label' => 'Free trial length',
                'help' => 'Applies to new sign-ups only. Shops already on trial keep the length they were given.',
                'type' => 'int',
                'unit' => 'days',
                'rules' => ['required', 'integer', 'min:0', 'max:365'],
            ],
            'subscription.grace_days' => [
                'group' => 'signup',
                'label' => 'Grace period after expiry',
                'help' => 'How long a shop keeps working after its subscription runs out, before the expiry behaviour applies.',
                'type' => 'int',
                'unit' => 'days',
                'rules' => ['required', 'integer', 'min:0', 'max:90'],
            ],

            // =============================================== billing (#115)
            //
            // ⚠️ THIS IS NOT THE SHOP'S CURRENCY. This is what KN Softic
            // charges a shop in — the figure on the pricing page and on a
            // subscription invoice. What a shop sells its own goods in is
            // `format.currency_*` in SettingRegistry, which each tenant sets
            // for itself. A shop in Lahore can bill its customers in PKR while
            // paying us in USD, and both are right at the same time.
            'subscription.currency' => [
                'group' => 'billing',
                'label' => 'Billing currency',
                'help' => 'The ISO code you charge shops in — USD, PKR, AED. Changing it does NOT convert existing prices; it relabels them, so set it before you have paying customers.',
                'type' => 'string',
                'rules' => ['required', 'string', 'size:3', 'alpha'],
            ],
            'subscription.currency_symbol' => [
                'group' => 'billing',
                'label' => 'Billing currency symbol',
                'help' => 'What the pricing page prints in front of the number. "Rs", "$", "د.إ".',
                'type' => 'string',
                'rules' => ['required', 'string', 'max:8'],
            ],
            'subscription.currency_decimals' => [
                'group' => 'billing',
                'label' => 'Decimal places',
                'help' => 'Two for most currencies. Zero where the smallest unit in circulation is the whole one.',
                'type' => 'int',
                'rules' => ['required', 'integer', 'min:0', 'max:4'],
            ],

            // ============================================ maintenance (#160)
            'platform.maintenance' => [
                'group' => 'maintenance',
                'label' => 'Maintenance mode',
                'help' => 'Closes every shop\'s workspace and the public site. This admin panel stays open — otherwise you would be locked out of the switch that turns it off.',
                'type' => 'bool',
                'rules' => ['boolean'],
            ],
            'platform.maintenance_message' => [
                'group' => 'maintenance',
                'label' => 'What shops are told',
                'type' => 'text',
                'rules' => ['required', 'string', 'max:500'],
            ],
            'platform.maintenance_token' => [
                'group' => 'maintenance',
                'label' => 'Preview token',
                'help' => 'Reach the app during maintenance with ?maintenance_token=… , to check a release before letting shops back in. Blank turns the escape hatch off.',
                'type' => 'string',
                'rules' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_]*$/'],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function groupLabels(): array
    {
        return [
            'branding' => 'Branding',
            'signup' => 'Sign-up & trials',
            'billing' => 'Billing',
            'maintenance' => 'Maintenance',
        ];
    }

    /** @return array<string, string> */
    public static function groupDescriptions(): array
    {
        return [
            'branding' => 'Whose product this is. Changing it here changes it everywhere — no deployment, no search and replace.',
            'signup' => 'Whether strangers may create an account, and what they get when they do.',
            'billing' => 'What YOU charge shops in — not what a shop charges its own customers, which each one sets in its own Settings.',
            'maintenance' => 'Taking the shops offline without locking yourself out.',
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array{group: string, label: string, type: string, rules: list<string>, help?: string, options?: array<string, string>, unit?: string} */
    public static function definition(string $key): array
    {
        $all = self::all();

        abort_unless(isset($all[$key]), 404, "No such platform setting: {$key}.");

        return $all[$key];
    }

    /** @return array<string, array<string, mixed>> */
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
