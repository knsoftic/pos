{{--
    ONE confirmation dialog for the whole application (#92).

    ================= WHY ONE, AND NOT ONE PER FORM =================
    Twenty-two destructive actions in this codebase used the browser's native
    `confirm()`. That dialog cannot be styled, cannot say which action is
    dangerous, renders differently in every browser, and on some mobile browsers
    can be suppressed entirely — which turns "are you sure?" into "done".

    Replacing it per form would have meant twenty-two components to keep in
    step. Instead there is one dialog here and a single listener: any form
    carrying `data-confirm` is intercepted, and `data-confirm-danger` turns the
    button red. Converting a form is one attribute, so none of them can drift.

    ================= WHAT MAKES IT SAFE =================
    The form is only submitted from the dialog's own confirm button, and the
    listener re-submits with a flag so it does not intercept itself. If the
    JavaScript never runs, the form submits normally — a broken dialog must not
    become a broken application.
--}}
<div x-data="confirmDialog()" x-on:confirm-request.window="open($event.detail)">
    <div x-show="showing" x-cloak
         class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-sm"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         @click.self="cancel()"
         @keydown.escape.window="cancel()"
         role="dialog" aria-modal="true">

        <div class="card w-full max-w-md p-6"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95">
            <div class="flex items-start gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl"
                      :class="danger
                        ? 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400'
                        : 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400'">
                    <x-icon name="alert" class="h-5 w-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <h2 class="font-semibold text-slate-900 dark:text-white" x-text="title"></h2>
                    <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-300" x-text="body"></p>
                </div>
            </div>

            <div class="mt-6 flex items-center justify-end gap-2">
                <button type="button" class="btn btn-secondary" @click="cancel()" x-ref="cancel">Cancel</button>
                <button type="button" @click="proceed()"
                        class="btn"
                        :class="danger ? 'btn-primary !bg-rose-600 hover:!bg-rose-700' : 'btn-primary'"
                        x-text="confirmLabel"></button>
            </div>
        </div>
    </div>
</div>

@once
    @push('scripts')
    <script>
        function confirmDialog() {
            return {
                showing: false,
                title: '',
                body: '',
                confirmLabel: 'Confirm',
                danger: true,
                form: null,

                open(detail) {
                    this.title = detail.title;
                    this.body = detail.body || '';
                    this.confirmLabel = detail.confirmLabel || 'Confirm';
                    this.danger = detail.danger !== false;
                    this.form = detail.form;
                    this.showing = true;

                    // Focus lands on CANCEL, not on the destructive button: a
                    // stray Enter should not delete anything.
                    this.$nextTick(() => this.$refs.cancel?.focus());
                },

                cancel() {
                    this.showing = false;
                    this.form = null;
                },

                proceed() {
                    const form = this.form;
                    this.showing = false;
                    this.form = null;
                    form?.requestSubmit ? form.requestSubmit() : form?.submit();
                },
            };
        }

        /*
         | One listener for every form in the application.
         |
         | Capture phase and `dataset.confirmed` together are what stop this
         | recursing: the dialog re-submits the same form, and without the flag
         | that submit would be intercepted again and nothing would ever send.
         */
        document.addEventListener('submit', (event) => {
            const form = event.target;

            if (! form.dataset || ! form.dataset.confirm || form.dataset.confirmed === '1') {
                return;
            }

            event.preventDefault();

            const proceed = () => {
                form.dataset.confirmed = '1';
                form.requestSubmit ? form.requestSubmit() : form.submit();
            };

            window.dispatchEvent(new CustomEvent('confirm-request', {
                detail: {
                    title: form.dataset.confirm,
                    body: form.dataset.confirmBody || '',
                    confirmLabel: form.dataset.confirmLabel || 'Yes, continue',
                    danger: form.dataset.confirmDanger !== '0',
                    form: { requestSubmit: proceed, submit: proceed },
                },
            }));
        }, true);
    </script>
    @endpush
@endonce
