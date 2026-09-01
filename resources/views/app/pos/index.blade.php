{{--
    The till (#14, #15, #16, #20, #89, #90, #91, #122, #146, #147, #148).

    THE CART LIVES HERE, IN THE BROWSER. Adding a line, nudging a quantity,
    taking a discount off — none of it touches the server, so none of it can be
    slow. The server is asked two things: "what matches this?" and "here is a
    finished sale" (#90, #122).

    None of what this file calculates is trusted. The server reprices every line
    from the ids it is sent, so a tampered cart simply gets the real prices back.
--}}
<x-layouts.app title="POS">

    <x-flash />

    <div
        x-data="till({
            favourites: @js($favourites),
            recent: @js($recent),
            customers: @js($customers),
            paymentMethods: @js($paymentMethods),
            creditMethod: @js($creditMethod),
            cashMethods: @js($cashMethods),
            rounding: {{ $rounding }},
            currency: @js($currency),
            canDiscount: {{ $canDiscount ? 'true' : 'false' }},
            discountCap: {{ $discountCap === null ? 'null' : $discountCap }},
            urls: {
                search: @js(route('app.pos.search')),
                scan: @js(route('app.pos.scan')),
                checkout: @js(route('app.pos.checkout')),
                hold: @js(route('app.pos.hold')),
                holds: @js(url('app/pos/holds')),
                customers: @js(route('app.pos.customers.store')),
                favourites: @js(url('app/pos/favourites')),
            },
        })"
        @keydown.window="shortcut($event)"
        class="grid grid-cols-1 gap-4 lg:grid-cols-12"
    >

        {{-- ═══════════════════════════ LEFT: categories (#14, #148) ═══════ --}}
        <aside class="lg:col-span-2">
            <div class="card p-3">
                <p class="px-2 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Categories</p>

                <button type="button" @click="filterCategory(null)"
                        :class="categoryId === null ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
                        class="mb-1 flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium">
                    <x-icon name="products" class="h-4 w-4" /> Everything
                </button>

                @foreach ($categories as $category)
                    <button type="button" @click="filterCategory({{ $category->id }})"
                            :class="categoryId === {{ $category->id }} ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
                            class="mb-1 flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium">
                        <span class="truncate">{{ $category->name }}</span>
                    </button>
                @endforeach
            </div>

            {{-- Held sales (#20) --}}
            <div class="card mt-4 p-3" x-show="holds.length" x-cloak>
                <p class="px-2 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    On hold <span x-text="'(' + holds.length + ')'"></span>
                </p>
                <template x-for="hold in holds" :key="hold.id">
                    <div class="mb-1 flex items-center gap-1">
                        <button type="button" @click="resumeHold(hold)"
                                class="flex-1 rounded-lg px-3 py-2 text-left text-sm text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800">
                            <span class="block font-medium" x-text="hold.reference"></span>
                            <span class="block text-xs text-slate-400" x-text="hold.label"></span>
                        </button>
                        <button type="button" @click="discardHold(hold)"
                                class="rounded-lg p-2 text-slate-400 hover:text-rose-600 dark:hover:text-rose-400" title="Discard">
                            <x-icon name="trash" class="h-4 w-4" />
                        </button>
                    </div>
                </template>
            </div>
        </aside>

        {{-- ═══════════════════════════ CENTRE: search + grid (#14, #15) ═══ --}}
        <section class="lg:col-span-6">
            <div class="card mb-4 p-4">
                <div class="relative">
                    <input x-ref="search" x-model="term" @input.debounce.150ms="runSearch()"
                           @keydown.enter.prevent="onEnter()"
                           type="search" autocomplete="off"
                           placeholder="Search or scan — name, SKU, barcode  (F2)"
                           class="input !py-3 !pl-11 text-base" />
                    <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400">
                        <x-icon name="search" class="h-5 w-5" />
                    </span>
                    <span x-show="searching" x-cloak
                          class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400">searching…</span>
                </div>

                <p class="mt-2 text-xs text-slate-400">
                    Scanning a barcode adds it straight to the cart. Shortcuts: F2 search · F4 customer · F6 hold ·
                    F8 pay · F9 discount · Esc clear
                </p>
            </div>

            {{-- The grid --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                <template x-for="product in visible" :key="product.id + '-' + (product.selected_variant_id || 0)">
                    <button type="button" @click="pick(product)"
                            class="card group relative flex flex-col overflow-hidden p-0 text-left transition hover:border-brand-300 hover:shadow-md dark:hover:border-brand-500/40">
                        <span class="flex h-24 w-full items-center justify-center overflow-hidden bg-slate-100 dark:bg-slate-800">
                            <template x-if="product.image">
                                <img :src="product.image" :alt="product.name" class="h-full w-full object-cover" />
                            </template>
                            <template x-if="! product.image">
                                <x-icon name="products" class="h-8 w-8 text-slate-300 dark:text-slate-600" />
                            </template>
                        </span>

                        <span class="flex flex-1 flex-col p-3">
                            <span class="line-clamp-2 text-sm font-medium text-slate-900 dark:text-white" x-text="product.name"></span>
                            <span class="mt-1 text-xs text-slate-400" x-text="product.sku"></span>

                            <span class="mt-auto flex items-end justify-between gap-2 pt-2">
                                <span class="font-semibold tabular-nums text-slate-900 dark:text-white"
                                      x-text="priceLabel(product)"></span>
                                <span x-show="product.tracks_stock" x-cloak
                                      :class="product.stock > 0 ? 'text-slate-400' : 'text-rose-500'"
                                      class="text-xs tabular-nums"
                                      x-text="product.stock > 0 ? product.stock + ' left' : 'none'"></span>
                            </span>
                        </span>

                        {{-- Pin to the grid (#147) --}}
                        <span @click.stop="toggleFavourite(product)"
                              class="absolute right-2 top-2 rounded-lg bg-white/90 p-1.5 text-slate-300 opacity-0 transition group-hover:opacity-100 hover:text-amber-500 dark:bg-slate-900/90"
                              :class="product.is_favourite ? 'opacity-100 text-amber-500' : ''"
                              :title="product.is_favourite ? 'Unpin' : 'Pin to the grid'">
                            <x-icon name="star" class="h-4 w-4" />
                        </span>
                    </button>
                </template>
            </div>

            <p x-show="! visible.length" x-cloak class="card mt-3 p-10 text-center text-sm text-slate-400">
                <span x-show="term">Nothing matches “<span x-text="term"></span>”.</span>
                <span x-show="! term">Nothing pinned yet — search for something, or pin what you sell most.</span>
            </p>
        </section>

        {{-- ═══════════════════════════ RIGHT: the cart (#14, #16) ═════════ --}}
        <aside class="lg:col-span-4">
            <div class="card sticky top-4 flex max-h-[calc(100vh-2rem)] flex-col">

                {{-- Till status (#139) --}}
                @if ($requiresSession && $session === null)
                    <div class="border-b border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                        <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">The till is closed</p>
                        <p class="mt-1 text-xs text-amber-700/90 dark:text-amber-300/80">
                            This shop records a cash session for every sale, so the drawer has to be counted in first.
                        </p>
                        <form method="POST" action="{{ route('app.pos.session.open') }}" class="mt-3 flex gap-2">
                            @csrf
                            <input name="opening_float" type="number" step="0.01" min="0" required
                                   placeholder="Opening float" class="input !py-2 text-sm" />
                            <button type="submit" class="btn btn-primary !py-2 text-sm whitespace-nowrap">Open till</button>
                        </form>
                    </div>
                @elseif ($session !== null)
                    <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-2 text-xs dark:border-slate-800">
                        <span class="text-slate-500 dark:text-slate-400">
                            Till open since {{ $session->opened_at->format('H:i') }}
                        </span>
                        <span class="tabular-nums text-slate-600 dark:text-slate-300">
                            {{ $currency }}{{ number_format($session->expectedCash(), 2) }} in the drawer
                        </span>
                    </div>
                @endif

                {{-- Customer (#16, #146) --}}
                <div class="border-b border-slate-100 p-4 dark:border-slate-800">
                    <div class="flex items-center gap-2">
                        <select x-model="customerId" class="input !py-2 text-sm" x-ref="customer">
                            <option value="">Walk-in customer</option>
                            <template x-for="c in customers" :key="c.id">
                                <option :value="c.id" x-text="c.name + (c.phone ? ' · ' + c.phone : '')"></option>
                            </template>
                        </select>

                        @if ($canAddCustomer)
                            <button type="button" @click="addingCustomer = ! addingCustomer"
                                    class="btn btn-secondary !px-2.5 !py-2" title="Add a customer (F4)">
                                <x-icon name="plus" class="h-4 w-4" />
                            </button>
                        @endif
                    </div>

                    {{-- Quick add: one field, because that is what a counter allows --}}
                    <div x-show="addingCustomer" x-cloak class="mt-2 flex gap-2">
                        <input x-model="newCustomer.name" type="text" placeholder="Name" class="input !py-2 text-sm" />
                        <input x-model="newCustomer.phone" type="text" placeholder="Phone" class="input !py-2 text-sm" />
                        <button type="button" @click="createCustomer()" :disabled="busy"
                                class="btn btn-primary !px-3 !py-2 text-sm">Add</button>
                    </div>

                    <p x-show="selectedCustomer()" x-cloak class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        <span x-text="creditLabel()"></span>
                    </p>
                </div>

                {{-- The lines --}}
                <div class="flex-1 overflow-y-auto">
                    <template x-if="! cart.length">
                        <p class="p-10 text-center text-sm text-slate-400">
                            Nothing in the cart yet. Scan something, or tap it on the left.
                        </p>
                    </template>

                    <template x-for="(line, i) in cart" :key="line.key">
                        <div class="border-b border-slate-100 p-3 dark:border-slate-800">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-900 dark:text-white" x-text="line.name"></p>
                                    <p class="text-xs text-slate-400" x-text="line.sku"></p>
                                </div>
                                <button type="button" @click="remove(i)"
                                        class="rounded-lg p-1 text-slate-300 hover:text-rose-600 dark:hover:text-rose-400">
                                    <x-icon name="trash" class="h-4 w-4" />
                                </button>
                            </div>

                            {{-- Wraps rather than scrolls: the cart column is
                                 narrow on a laptop and narrower still on a
                                 tablet, and a line whose controls slide off the
                                 edge is a line a cashier cannot correct. --}}
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <div class="flex items-center rounded-lg border border-slate-200 dark:border-slate-700">
                                    <button type="button" @click="nudge(i, -1)"
                                            class="px-2.5 py-1.5 text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">−</button>
                                    <input x-model.number="line.quantity" @change="normalise(i)" type="number" step="0.0001" min="0"
                                           class="w-14 border-0 bg-transparent px-1 py-1.5 text-center text-sm tabular-nums focus:ring-0" />
                                    <button type="button" @click="nudge(i, 1)"
                                            class="px-2.5 py-1.5 text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">+</button>
                                </div>

                                <input x-model.number="line.unit_price" type="number" step="0.01" min="0"
                                       class="input !w-20 !py-1.5 text-right text-sm tabular-nums" title="Price" />

                                @if ($canDiscount)
                                    <input x-model.number="line.discount_amount" type="number" step="0.01" min="0"
                                           placeholder="Disc"
                                           class="input !w-16 !py-1.5 text-right text-sm tabular-nums" title="Discount" />
                                @endif

                                <span class="ml-auto text-sm font-semibold tabular-nums text-slate-900 dark:text-white"
                                      x-text="money(lineNet(line))"></span>
                            </div>

                            <p x-show="overCap(line)" x-cloak class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                                Over your discount limit — a manager will need to approve it.
                            </p>
                        </div>
                    </template>
                </div>

                {{-- Totals + pay --}}
                <div class="border-t border-slate-200 p-4 dark:border-slate-800">
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">Subtotal</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200" x-text="money(subtotal())"></dd>
                        </div>
                        <div class="flex justify-between" x-show="discounts() > 0" x-cloak>
                            <dt class="text-slate-500 dark:text-slate-400">Discounts</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200" x-text="'−' + money(discounts())"></dd>
                        </div>
                        <div class="flex justify-between" x-show="tax() > 0" x-cloak>
                            <dt class="text-slate-500 dark:text-slate-400">Tax</dt>
                            <dd class="tabular-nums text-slate-700 dark:text-slate-200" x-text="money(tax())"></dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-100 pt-2 dark:border-slate-800">
                            <dt class="font-semibold text-slate-900 dark:text-white">Total</dt>
                            <dd class="text-xl font-bold tabular-nums text-slate-900 dark:text-white" x-text="money(total())"></dd>
                        </div>
                    </dl>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" @click="hold()" :disabled="! cart.length || busy"
                                class="btn btn-secondary disabled:opacity-40">
                            <x-icon name="clock" class="h-4 w-4" /> Hold (F6)
                        </button>
                        <button type="button" @click="openPayment()" :disabled="! cart.length || busy"
                                class="btn btn-primary disabled:opacity-40">
                            <x-icon name="credit-card" class="h-4 w-4" /> Pay (F8)
                        </button>
                    </div>
                </div>
            </div>
        </aside>

        {{-- ═══════════════════════════ PAYMENT (#17, #19) ═════════════════ --}}
        <div x-show="paying" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
             @click.self="paying = false">
            <div class="card w-full max-w-lg p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Take payment</h3>
                        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400" x-text="customerLabel()"></p>
                    </div>
                    <p class="text-2xl font-bold tabular-nums text-slate-900 dark:text-white" x-text="money(total())"></p>
                </div>

                <div class="mt-4 space-y-2">
                    <template x-for="(tender, i) in tenders" :key="i">
                        <div class="flex items-center gap-2">
                            <select x-model="tender.method" class="input !py-2 text-sm">
                                <template x-for="m in paymentMethods" :key="m">
                                    <option :value="m" x-text="headline(m)"></option>
                                </template>
                            </select>
                            <input x-model.number="tender.amount" type="number" step="0.01" min="0"
                                   class="input !py-2 text-right text-sm tabular-nums" />
                            <button type="button" @click="tenders.splice(i, 1)" x-show="tenders.length > 1"
                                    class="rounded-lg p-2 text-slate-400 hover:text-rose-600">
                                <x-icon name="trash" class="h-4 w-4" />
                            </button>
                        </div>
                    </template>

                    <button type="button" @click="addTender()" class="btn btn-ghost !px-0 text-sm text-brand-600 dark:text-brand-400">
                        <x-icon name="plus" class="h-4 w-4" /> Split across another method
                    </button>
                </div>

                {{-- Quick cash buttons: what a customer actually hands over --}}
                <div class="mt-3 flex flex-wrap gap-2" x-show="isCashTender()">
                    <template x-for="note in quickCash()" :key="note">
                        <button type="button" @click="tenders[0].amount = note"
                                class="btn btn-secondary !py-1.5 text-sm tabular-nums" x-text="money(note)"></button>
                    </template>
                </div>

                <dl class="mt-4 space-y-1 border-t border-slate-100 pt-3 text-sm dark:border-slate-800">
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Tendered</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200" x-text="money(tendered())"></dd>
                    </div>
                    <div class="flex justify-between" x-show="change() > 0" x-cloak>
                        <dt class="font-medium text-emerald-600 dark:text-emerald-400">Change</dt>
                        <dd class="font-semibold tabular-nums text-emerald-600 dark:text-emerald-400" x-text="money(change())"></dd>
                    </div>
                    <div class="flex justify-between" x-show="shortfall() > 0" x-cloak>
                        <dt class="font-medium text-amber-600 dark:text-amber-400">Goes on account</dt>
                        <dd class="font-semibold tabular-nums text-amber-600 dark:text-amber-400" x-text="money(shortfall())"></dd>
                    </div>
                </dl>

                <p x-show="error" x-cloak class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"
                   x-text="error"></p>

                <div class="mt-4 flex gap-2">
                    <button type="button" @click="paying = false" class="btn btn-secondary flex-1">Back</button>
                    {{-- #91: disabled while in flight, and the request carries an
                         idempotency key so a retry cannot make a second sale. --}}
                    <button type="button" @click="checkout()" :disabled="busy" class="btn btn-primary flex-1 disabled:opacity-40">
                        <span x-show="! busy"><x-icon name="check" class="h-4 w-4" /> Complete sale</span>
                        <span x-show="busy" x-cloak>Working…</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════ DONE (#145) ════════════════════════ --}}
        <div x-show="done" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="card w-full max-w-sm p-6 text-center">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400">
                    <x-icon name="check-circle" class="h-7 w-7" />
                </span>

                <h3 class="mt-4 text-lg font-semibold text-slate-900 dark:text-white" x-text="done?.invoice_no"></h3>
                <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900 dark:text-white" x-text="money(done?.total || 0)"></p>

                <p x-show="done?.change > 0" x-cloak class="mt-2 text-sm text-emerald-600 dark:text-emerald-400">
                    Change <span class="font-semibold tabular-nums" x-text="money(done?.change || 0)"></span>
                </p>
                <p x-show="done?.due > 0" x-cloak class="mt-2 text-sm text-amber-600 dark:text-amber-400">
                    <span class="font-semibold tabular-nums" x-text="money(done?.due || 0)"></span> on account
                </p>

                {{-- #145: print, or move straight on. The receipt opens in its
                     own tab so the till itself never navigates away — the next
                     customer is already waiting. --}}
                <div class="mt-5 grid grid-cols-2 gap-2">
                    <a :href="'/app/sales/' + done?.id + '/receipt'" target="_blank" class="btn btn-secondary">
                        <x-icon name="printer" class="h-4 w-4" /> Receipt
                    </a>
                    <button type="button" @click="newSale()" class="btn btn-primary">
                        New sale (Esc)
                    </button>
                </div>

                <a :href="'/app/sales/' + done?.id" class="mt-2 block text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                    View the sale
                </a>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        /*
         | The till's whole state machine. Kept in one Alpine component rather
         | than spread across the markup so the cart rules — what a line is
         | worth, what is still owed — are defined once and read once.
         |
         | NOTE the server re-derives all of it. Nothing here is authoritative;
         | it exists to make the screen instant (#122).
         */
        function till(config) {
            return {
                ...config,

                term: '',
                categoryId: null,
                results: null,
                searching: false,

                cart: [],
                customerId: '',
                addingCustomer: false,
                newCustomer: { name: '', phone: '' },

                paying: false,
                tenders: [],
                busy: false,
                error: '',
                done: null,

                holds: @js($holds->map(fn ($h) => [
                    'id' => $h->id,
                    'reference' => $h->invoice_no,
                    'label' => ($h->customer?->name ?? 'Walk-in') . ' · ' . number_format((float) $h->total, 2),
                ])),

                // One key per cart. Regenerated after every completed sale, so
                // the next basket is genuinely a different request (#91).
                idempotencyKey: crypto.randomUUID(),

                // ---------------------------------------------------- grid
                get visible() {
                    if (this.results !== null) return this.results;
                    return this.favourites.length ? this.favourites : this.recent;
                },

                async runSearch() {
                    const term = this.term.trim();

                    if (! term && this.categoryId === null) { this.results = null; return; }

                    this.searching = true;
                    try {
                        const url = new URL(this.urls.search, window.location.origin);
                        if (term) url.searchParams.set('q', term);
                        if (this.categoryId !== null) url.searchParams.set('category', this.categoryId);

                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        this.results = data.products;
                    } finally {
                        this.searching = false;
                    }
                },

                filterCategory(id) {
                    this.categoryId = this.categoryId === id ? null : id;
                    this.runSearch();
                },

                /*
                 | Enter in the search box means one of two things: a scanner has
                 | just typed a barcode, or a person has typed a search and wants
                 | the first result. Try the barcode first — an exact code is
                 | unambiguous — and fall back to the top match.
                 */
                async onEnter() {
                    const code = this.term.trim();
                    if (! code) return;

                    const res = await fetch(this.urls.scan + '?barcode=' + encodeURIComponent(code), {
                        headers: { 'Accept': 'application/json' },
                    });
                    const data = await res.json();

                    if (data.product) {
                        this.pick(data.product, data.product.selected_variant_id);
                        this.term = '';
                        this.results = null;
                        return;
                    }

                    await this.runSearch();
                    if (this.visible.length) this.pick(this.visible[0]);
                },

                async toggleFavourite(product) {
                    const res = await fetch(this.urls.favourites + '/' + product.id, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                    });
                    const data = await res.json();
                    product.is_favourite = data.is_favourite;
                },

                // ---------------------------------------------------- cart
                pick(product, variantId = null) {
                    // A variable product needs to know WHICH one; with exactly
                    // one variant there is no choice to make, so do not ask.
                    if (! variantId && product.variants && product.variants.length) {
                        if (product.variants.length === 1) {
                            variantId = product.variants[0].id;
                        } else {
                            const names = product.variants.map((v, i) => (i + 1) + '. ' + v.name).join('\n');
                            const choice = window.prompt(product.name + ' — which one?\n' + names, '1');
                            const index = parseInt(choice, 10) - 1;
                            if (isNaN(index) || ! product.variants[index]) return;
                            variantId = product.variants[index].id;
                        }
                    }

                    const variant = (product.variants || []).find(v => v.id === variantId);
                    const key = product.id + '-' + (variantId || 0);
                    const existing = this.cart.find(l => l.key === key);

                    if (existing) { existing.quantity = this.round(existing.quantity + 1, 4); return; }

                    this.cart.push({
                        key,
                        product_id: product.id,
                        variant_id: variantId,
                        name: variant ? product.name + ' — ' + variant.name : product.name,
                        sku: variant ? variant.sku : product.sku,
                        quantity: 1,
                        unit_price: variant ? variant.price : product.price,
                        discount_amount: 0,
                        tax_rate: product.tax_rate || 0,
                    });
                },

                nudge(i, by) {
                    this.cart[i].quantity = this.round(Math.max(0, (this.cart[i].quantity || 0) + by), 4);
                    if (this.cart[i].quantity <= 0) this.remove(i);
                },

                normalise(i) {
                    if (! (this.cart[i].quantity > 0)) this.remove(i);
                },

                remove(i) { this.cart.splice(i, 1); },

                clear() {
                    this.cart = [];
                    this.customerId = '';
                    this.tenders = [];
                    this.error = '';
                },

                // -------------------------------------------------- money
                lineGross(line) { return (line.quantity || 0) * (line.unit_price || 0); },
                lineNet(line) {
                    const afterDiscount = Math.max(0, this.lineGross(line) - (line.discount_amount || 0));
                    return afterDiscount * (1 + ((line.tax_rate || 0) / 100));
                },
                subtotal() { return this.cart.reduce((s, l) => s + this.lineGross(l), 0); },
                discounts() { return this.cart.reduce((s, l) => s + (l.discount_amount || 0), 0); },
                tax() {
                    return this.cart.reduce((s, l) => {
                        const afterDiscount = Math.max(0, this.lineGross(l) - (l.discount_amount || 0));
                        return s + (afterDiscount * ((l.tax_rate || 0) / 100));
                    }, 0);
                },
                total() {
                    const net = this.cart.reduce((s, l) => s + this.lineNet(l), 0);
                    if (! this.rounding) return this.round(net, 2);
                    return this.round(Math.round(net / this.rounding) * this.rounding, 2);
                },

                tendered() { return this.tenders.filter(t => t.method !== this.creditMethod).reduce((s, t) => s + (t.amount || 0), 0); },
                change() { return Math.max(0, this.round(this.tendered() - this.total(), 2)); },
                shortfall() { return Math.max(0, this.round(this.total() - this.tendered(), 2)); },

                overCap(line) {
                    if (this.discountCap === null || ! (line.discount_amount > 0)) return false;
                    const gross = this.lineGross(line);
                    return gross > 0 && ((line.discount_amount / gross) * 100) > this.discountCap + 0.005;
                },

                // ---------------------------------------------- customers
                selectedCustomer() {
                    return this.customers.find(c => String(c.id) === String(this.customerId)) || null;
                },
                customerLabel() {
                    const c = this.selectedCustomer();
                    return c ? c.name : 'Walk-in customer';
                },
                creditLabel() {
                    const c = this.selectedCustomer();
                    if (! c) return '';
                    if (c.credit_limit === null) return 'No credit limit. Owes ' + this.money(c.balance);
                    if (! c.credit_limit) return 'Cash only. Owes ' + this.money(c.balance);
                    return this.money(Math.max(0, c.credit_limit - c.balance)) + ' of credit available';
                },

                async createCustomer() {
                    if (! this.newCustomer.name.trim()) return;
                    this.busy = true;
                    try {
                        const res = await fetch(this.urls.customers, {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify(this.newCustomer),
                        });
                        const data = await res.json();
                        if (! res.ok) { this.error = data.message || 'Could not add that customer.'; return; }

                        this.customers.unshift(data.customer);
                        this.customerId = data.customer.id;
                        this.newCustomer = { name: '', phone: '' };
                        this.addingCustomer = false;
                    } finally { this.busy = false; }
                },

                // -------------------------------------------------- paying
                openPayment() {
                    if (! this.cart.length) return;
                    this.error = '';
                    this.tenders = [{ method: this.paymentMethods[0], amount: this.total() }];
                    this.paying = true;
                },
                addTender() { this.tenders.push({ method: this.paymentMethods[0], amount: Math.max(0, this.shortfall()) }); },
                isCashTender() { return this.tenders.length === 1 && this.cashMethods.includes(this.tenders[0].method); },
                quickCash() {
                    const total = this.total();
                    const notes = [total];
                    [50, 100, 500, 1000, 5000].forEach(n => {
                        const up = Math.ceil(total / n) * n;
                        if (up > total && ! notes.includes(up)) notes.push(up);
                    });
                    return notes.slice(0, 5);
                },

                async checkout() {
                    if (this.busy) return;          // #91, first line of defence
                    this.busy = true;
                    this.error = '';

                    try {
                        const res = await fetch(this.urls.checkout, {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                idempotency_key: this.idempotencyKey,
                                customer_id: this.customerId || null,
                                lines: this.cart.map(l => ({
                                    product_id: l.product_id,
                                    variant_id: l.variant_id,
                                    quantity: l.quantity,
                                    unit_price: l.unit_price,
                                    discount_amount: l.discount_amount || 0,
                                    tax_rate: l.tax_rate || 0,
                                })),
                                payments: this.tenders.filter(t => t.amount > 0),
                            }),
                        });

                        const data = await res.json();

                        if (! res.ok) {
                            this.error = data.message
                                || (data.errors ? Object.values(data.errors).flat()[0] : 'That sale could not be completed.');
                            return;
                        }

                        this.paying = false;
                        this.done = data.sale;
                    } catch (e) {
                        this.error = 'Could not reach the server. Nothing was charged — try again.';
                    } finally {
                        this.busy = false;
                    }
                },

                newSale() {
                    this.done = null;
                    this.clear();
                    // A fresh key: the next basket is a genuinely new request.
                    this.idempotencyKey = crypto.randomUUID();
                    this.$refs.search?.focus();
                },

                // --------------------------------------------------- holds
                async hold() {
                    if (! this.cart.length || this.busy) return;
                    this.busy = true;
                    try {
                        const res = await fetch(this.urls.hold, {
                            method: 'POST',
                            headers: this.headers(),
                            body: JSON.stringify({
                                customer_id: this.customerId || null,
                                lines: this.cart.map(l => ({
                                    product_id: l.product_id,
                                    variant_id: l.variant_id,
                                    quantity: l.quantity,
                                    unit_price: l.unit_price,
                                    discount_amount: l.discount_amount || 0,
                                    tax_rate: l.tax_rate || 0,
                                })),
                            }),
                        });
                        const data = await res.json();
                        if (! res.ok) { this.error = data.message || 'Could not hold that.'; return; }

                        this.holds.unshift({
                            id: data.sale.id,
                            reference: data.sale.reference,
                            label: this.customerLabel() + ' · ' + this.money(this.total()),
                        });
                        this.clear();
                    } finally { this.busy = false; }
                },

                async resumeHold(hold) {
                    const res = await fetch(this.urls.holds + '/' + hold.id, { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    if (! res.ok) return;

                    this.cart = data.lines.map(l => ({
                        key: l.product_id + '-' + (l.variant_id || 0),
                        product_id: l.product_id,
                        variant_id: l.variant_id,
                        name: l.name,
                        sku: l.sku,
                        quantity: l.quantity,
                        unit_price: l.unit_price,
                        discount_amount: l.discount_amount,
                        tax_rate: l.tax_rate,
                    }));
                    this.customerId = data.customer_id || '';

                    await this.discardHold(hold);
                },

                async discardHold(hold) {
                    await fetch(this.urls.holds + '/' + hold.id, {
                        method: 'DELETE',
                        headers: this.headers(),
                    });
                    this.holds = this.holds.filter(h => h.id !== hold.id);
                },

                // ----------------------------------------------- shortcuts
                /*
                 | #89. Function keys because a till is used with hands on the
                 | counter, not on a mouse — and because they do not collide with
                 | typing a product name into the search box.
                 */
                shortcut(e) {
                    const keys = {
                        F2: () => this.$refs.search?.focus(),
                        F4: () => this.$refs.customer?.focus(),
                        F6: () => this.hold(),
                        F8: () => this.openPayment(),
                        F9: () => this.focusDiscount(),
                    };

                    if (keys[e.key]) { e.preventDefault(); keys[e.key](); return; }

                    if (e.key === 'Escape') {
                        if (this.done) { this.newSale(); return; }
                        if (this.paying) { this.paying = false; return; }
                        this.clear();
                    }
                },

                focusDiscount() {
                    const inputs = this.$el.querySelectorAll('input[title="Discount"]');
                    inputs[inputs.length - 1]?.focus();
                },

                // ------------------------------------------------- helpers
                headers() {
                    return {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrf(),
                    };
                },
                csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; },
                round(v, dp) { const f = Math.pow(10, dp); return Math.round((v + Number.EPSILON) * f) / f; },
                money(v) {
                    return this.currency + (v || 0).toLocaleString(undefined, {
                        minimumFractionDigits: 2, maximumFractionDigits: 2,
                    });
                },
                headline(s) { return String(s).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); },
                priceLabel(p) {
                    if (p.price_from !== p.price_to) return this.money(p.price_from) + '+';
                    return this.money(p.price_from || p.price);
                },
            };
        }
    </script>
    @endpush

</x-layouts.app>
