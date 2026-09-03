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
    listener re-submits with a flag so it does not intercept itself.

    ⚠️ THE FAILURE THAT WAS HERE, because it is the one worth remembering.
    "If the JavaScript never runs, the form submits normally" was written here
    and was NOT true. Two different things run: this listener is plain inline
    JavaScript, while the dialog that answers it is Alpine. On the live site
    Livewire's asset 404'd and Alpine — which ships inside Livewire here — never
    loaded. So the listener ran, called preventDefault(), dispatched into a room
    with nobody in it, and every confirm button in the application became a
    button that did nothing at all. No error, no dialog, no submit.

    Now the dialog marks the event as handled. If nothing marks it, the browser's
    own confirm() is used instead — the very dialog this component replaced, kept
    as the floor rather than the ceiling. Should even that be suppressed (some
    mobile browsers do), it returns false and the destructive action does not
    happen, which is the safe direction to fail in.
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
                    // Tells the dispatcher somebody is home. Without this the
                    // page cannot tell "dialog shown" from "Alpine never
                    // loaded", and those two must not look the same.
                    detail.handled = true;

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

            const detail = {
                title: form.dataset.confirm,
                body: form.dataset.confirmBody || '',
                confirmLabel: form.dataset.confirmLabel || 'Yes, continue',
                danger: form.dataset.confirmDanger !== '0',
                handled: false,
                form: { requestSubmit: proceed, submit: proceed },
            };

            // Alpine's listener is synchronous, so by the time dispatchEvent
            // returns, `handled` is settled.
            window.dispatchEvent(new CustomEvent('confirm-request', { detail }));

            if (detail.handled) {
                return;
            }

            /*
             | ⚠️ Nobody answered — Alpine is not running. This listener has
             | already cancelled the submit, so doing nothing here would leave a
             | dead button, which is what happened in production.
             |
             | Fall back to the browser's own dialog. It is ugly and it is the
             | thing this component was written to replace, but an ugly "are you
             | sure?" beats a button that silently ignores you. If the browser
             | suppresses it, confirm() returns false and the destructive action
             | does not happen — the safe direction.
             */
            if (window.confirm(detail.title + (detail.body ? '\n\n' + detail.body : ''))) {
                proceed();
            }
        }, true);
    </script>
    @endpush
@endonce
