{{--
    Shown to a browser this app cannot render on (#180).

    ─────────────────────────────────────────────────────────────────────────────
    WHERE THE FLOOR ACTUALLY COMES FROM

    Not from a decision we made about which browsers to support. Tailwind CSS v4
    is built on `color-mix()`, `@property` and cascade layers, so the styling
    either works or it does not — there is no degraded version. That puts the
    real floor at roughly Chrome/Edge 111, Firefox 128 and Safari 16.4, which is
    higher than Vite's own default target and worth being honest about.

    ⚠️ FEATURE DETECTION, NOT USER-AGENT SNIFFING. A UA string is a claim the
    browser makes about itself, every vendor has lied in one for twenty years,
    and a sniff written today refuses a browser released next year. `@supports`
    asks the only question that matters: can you draw this?

    ─────────────────────────────────────────────────────────────────────────────
    WHY IT MATTERS MORE HERE THAN ON A NORMAL SITE

    The till in a shop is frequently the oldest computer in the building. Without
    this, an unsupported browser gets an unstyled, unusable pile of text and no
    explanation — and the shopkeeper's reasonable conclusion is that the product
    is broken, which is a support call nobody can resolve over the phone.

    Deliberately: no external CSS, no JavaScript, no Blade helpers beyond the
    brand name. Everything a browser too old to render the app needs in order to
    be told so is inline, because the stylesheet it cannot parse is exactly the
    thing that would have hidden this.
--}}
<style>
    #kn-browser-notice {
        display: block;
        margin: 0;
        padding: 18px 20px;
        background: #7f1d1d;
        color: #fff;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 14px;
        line-height: 1.6;
        text-align: center;
    }
    #kn-browser-notice b { display: block; font-size: 16px; margin-bottom: 4px; }
    #kn-browser-notice a { color: #fff; }

    /*
      The one thing every supported browser can do and no unsupported one can.
      Supported browsers therefore never see the element at all — this is the
      hide rule, not the show rule, so a browser that cannot parse @supports
      keeps the notice, which is the correct way round.
    */
    @supports (color: color-mix(in lab, red, red)) {
        #kn-browser-notice { display: none; }
    }
</style>

<div id="kn-browser-notice">
    <b>This browser is too old to run {{ config('brand.product') }}.</b>
    Please update it, or open the app in an up-to-date Chrome or Edge.
    Parts of this page will not display correctly until you do.
</div>
