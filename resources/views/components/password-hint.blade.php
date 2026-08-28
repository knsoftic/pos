@php
    /**
     * Human-readable summary of the active password policy.
     *
     * Built in PHP rather than with inline @if directives: Blade only compiles a
     * directive when the `@` is not preceded by a word character, so mid-sentence
     * conditionals silently break the template. Reading the rules from
     * config('security.password') also means the hint can never drift out of
     * sync with what Password::defaults() actually enforces. #63 / #190
     */
    $policy = config('security.password');

    $requirements = [];

    if ($policy['require_mixed_case']) {
        $requirements[] = 'upper + lower case';
    }
    if ($policy['require_numbers']) {
        $requirements[] = 'a number';
    }
    if ($policy['require_symbols']) {
        $requirements[] = 'a symbol';
    }

    $hint = 'At least '.$policy['min_length'].' characters';

    if ($requirements !== []) {
        $hint .= ', including '.implode(', ', $requirements);
    }
@endphp

<p {{ $attributes->merge(['class' => 'mt-1.5 text-xs text-slate-400']) }}>{{ $hint }}.</p>
