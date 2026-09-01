<?php

namespace App\Support;

/**
 * What the public website says (#106, #107).
 *
 * ================= WHY THE COPY IS HERE AND NOT IN THE BLADES =================
 * Five of the marketing pages are the same page with different words: a hero, a
 * list of things the software does, a couple of proof points, a call to action.
 * Written as five templates they would drift — one would get a new section, one
 * would keep an old screenshot, and the tenth edit would be made in four of
 * them.
 *
 * So the SHAPE lives in one Blade file and the WORDS live here. Adding a page
 * is an entry in this array; changing a claim is one line, in the one place a
 * non-developer can be pointed at.
 *
 * ⚠️ Everything here is read by a public, unauthenticated page. It must contain
 * nothing about a tenant, and no claim the software cannot actually do — every
 * bullet below names something built in phases 1–11.
 */
class MarketingContent
{
    /**
     * The pages under /features and friends, keyed by their URL slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pages(): array
    {
        return [
            'features' => [
                'nav' => 'Features',
                'title' => 'Everything a shop actually runs on',
                'lead' => 'Not a list of modules — the things that happen between opening the shutters and cashing up.',
                'eyebrow' => 'The whole system',
                'sections' => [
                    [
                        'title' => 'Sell',
                        'body' => 'A till that stays fast on a bad connection, split payments, held sales, returns that put the right stock back and refunds that move the drawer.',
                        'points' => [
                            'Barcode-first search, favourites preloaded so the grid is never empty',
                            'Split tender across cash, card, wallet or the customer\'s account',
                            'Hold a sale and come back to it — held sales post nothing and spend no invoice number',
                            'Returns decide per line whether the goods go back on the shelf',
                        ],
                    ],
                    [
                        'title' => 'Know',
                        'body' => 'Stock that is the truth rather than a guess, and a profit figure you can check.',
                        'points' => [
                            'Every movement is a ledger line; the quantity column is only a cache of it',
                            'Weighted average cost, snapshotted when a sale happens — last month\'s margin never changes',
                            'Batches and expiry dates, consumed first-expiry-first-out',
                            'Thirty reports, each of them net of returns and excluding tax from revenue',
                        ],
                    ],
                    [
                        'title' => 'Grow',
                        'body' => 'More than one shop, more than one till, and a team you can trust with exactly as much as you choose.',
                        'points' => [
                            'Branches with their own stock, staff and figures — and transfers between them',
                            'Roles and permissions down to "may see profit" and "may hand money back"',
                            'Customer and supplier accounts with statements that foot',
                            'Expenses, other income and a profit & loss that adds up on the page',
                        ],
                    ],
                ],
            ],

            'pos' => [
                'nav' => 'POS',
                'title' => 'A till that keeps up with the queue',
                'lead' => 'The cart lives in the browser, so nothing you do at the counter waits for the internet.',
                'eyebrow' => 'Point of sale',
                'sections' => [
                    [
                        'title' => 'Fast where it matters',
                        'body' => 'Every cart action — a quantity nudge, a line discount, removing something — happens immediately, because none of them touch the server. The server is asked exactly two questions: what matches this, and here is a finished sale.',
                        'points' => [
                            'Scan or search; Enter tries the barcode first, then the closest match',
                            'Quick-cash buttons for what a customer actually hands over',
                            'The drawer knows what it should hold, so a cash-up can balance',
                            'Receipts on 58 mm, 80 mm or A4 from one template',
                        ],
                    ],
                    [
                        'title' => 'Honest by construction',
                        'body' => 'Nothing the browser sends is trusted. Prices, stock, credit limits and totals are all recomputed on the server from the ids the till sent — a tampered price produces a sale that shows a loss, not a discount.',
                        'points' => [
                            'Double-submit protection by idempotency key, not a disabled button',
                            'A sale is sixteen steps in one transaction, or none of them',
                            'A voided sale keeps its record and reverses its postings',
                            'A reprint is counted and says on its face that it is a copy',
                        ],
                    ],
                ],
            ],

            'inventory' => [
                'nav' => 'Inventory',
                'title' => 'Stock you can argue with',
                'lead' => 'Every figure traces back to a movement, and every movement says who and why.',
                'eyebrow' => 'Inventory',
                'sections' => [
                    [
                        'title' => 'The ledger is the truth',
                        'body' => 'Stock is not a number somebody edits. It is the sum of what came in and what went out, per branch, and the cached quantity is rebuilt from that ledger on demand — so "why is this wrong?" is always answerable.',
                        'points' => [
                            'Purchases, sales, returns, transfers, adjustments and stock takes, all in one history',
                            'A manual adjustment cannot be saved without a reason',
                            'Negative stock is a choice the shop makes, not an accident',
                            'Low-stock and out-of-stock lists that reflect the shelf right now',
                        ],
                    ],
                    [
                        'title' => 'Batches, expiry and branches',
                        'body' => 'One shelf can hold three deliveries with three expiry dates. Goods leave first-expiry-first, and a transfer between branches is a journey with a middle — what left, what arrived, and what never did.',
                        'points' => [
                            'Expiry warnings before the stock becomes worthless',
                            'Transfers show shortfalls instead of quietly reconciling them',
                            'Barcode labels, EAN-13, printed in sheets',
                            'CSV import and export, all-or-nothing with line numbers',
                        ],
                    ],
                ],
            ],

            'reports' => [
                'nav' => 'Reports',
                'title' => 'Numbers you can act on',
                'lead' => 'Thirty reports that agree with each other, because they all read the same definitions.',
                'eyebrow' => 'Reporting',
                'sections' => [
                    [
                        'title' => 'Accurate, or not worth having',
                        'body' => 'A report that is approximately right is worse than none, because the shop will act on it. Held sales have not happened, voided ones have been undone, returns are subtracted and shown, and tax is never counted as revenue.',
                        'points' => [
                            'Sales by day, product, category, customer, employee, branch, till and payment method',
                            'Profit at the cost that applied when it sold — a closed month stays closed',
                            'Stock on hand, valuation, low, out, movements, adjustments, expiry, transfers',
                            'Purchases, suppliers, unpaid bills, customer balances and statements',
                        ],
                    ],
                    [
                        'title' => 'And out of the system',
                        'body' => 'A report you cannot get out is a report you cannot check. CSV comes with every plan; spreadsheets and PDF come with the paid ones.',
                        'points' => [
                            'Real .xlsx — numbers arrive as numbers, sortable and summable',
                            'PDF laid out for the page, not a screenshot of a screen',
                            'A profit & loss that adds up when you check the subtraction yourself',
                            'Every export says what it is and which period it covers',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The three things the home page leads with (#107).
     *
     * @return array<int, array<string, string>>
     */
    public static function pillars(): array
    {
        return [
            [
                'icon' => 'pos',
                'title' => 'Sell without waiting',
                'body' => 'The cart is in the browser. A shop\'s connection is usually the worst thing it owns, and a till that needs a round trip to add a bottle of water is unusable.',
            ],
            [
                'icon' => 'inventory',
                'title' => 'Stock that traces',
                'body' => 'Every figure is the sum of movements you can read, not a number somebody typed. When it is wrong, you can find out why.',
            ],
            [
                'icon' => 'reports',
                'title' => 'Profit you can check',
                'body' => 'One definition of revenue, one of cost, used by every screen. The statement adds up on the page.',
            ],
        ];
    }

    /**
     * Questions people actually ask before signing up (#106).
     *
     * @return array<int, array<string, string>>
     */
    public static function faqs(): array
    {
        return [
            [
                'q' => 'Does it work when the internet drops?',
                'a' => 'The till keeps taking items and building the basket, because the cart lives in the browser. Completing a sale needs the connection back — that is the moment stock moves and money is recorded, and doing that offline would mean two tills selling the same last unit.',
            ],
            [
                'q' => 'Can I run more than one shop?',
                'a' => 'Yes. Branches each keep their own stock, staff and figures, and you can move stock between them. Branch reports add up to the business, and an owner sees everything while staff see their own branch.',
            ],
            [
                'q' => 'What happens to my data if I stop paying?',
                'a' => 'Nothing is deleted. Depending on the plan the workspace becomes read-only after a grace period, so you can still open your records and export them. Financial records are never deleted by the software at all — a mistake is corrected, not erased.',
            ],
            [
                'q' => 'Can I try it first?',
                'a' => 'Yes — a free trial with no card. You get the real system with your own shop, not a demo, so anything you set up is still there if you continue.',
            ],
            [
                'q' => 'Do my staff see everything?',
                'a' => 'Only what you allow. Permissions go down to the level of "may see profit", "may hand money back" and "may change settings", and a cashier can be limited to one branch and one till.',
            ],
            [
                'q' => 'Can it handle my currency and tax?',
                'a' => 'Currency symbol, position, decimals and separators are all settings, as are date formats and your timezone. Tax rates are a list you define, and each product carries the rate that applied when it was sold — so a rate change never rewrites an old invoice.',
            ],
            [
                'q' => 'Can I get my data out?',
                'a' => 'Every report exports to CSV on every plan, and to Excel and PDF on the paid ones. Products import and export as CSV too.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function page(string $slug): array
    {
        $pages = self::pages();

        abort_unless(isset($pages[$slug]), 404);

        return $pages[$slug];
    }

    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::pages());
    }
}
