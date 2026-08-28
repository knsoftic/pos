{{--
    The vendor credit inside a tenant's own workspace.

    Deliberately quiet. A shopkeeper's staff are working in THEIR shop's system,
    and the supplier's name shouting from the chrome would make it feel like
    someone else's product. One small line, once per screen, is the whole of
    KN Softic's presence at /app — see the note in config/brand.php.
--}}
@props(['muted' => false])

<a href="{{ config('brand.website') }}" target="_blank" rel="noopener noreferrer"
   {{ $attributes->merge([
       'class' => 'group inline-flex items-center gap-1.5 text-[11px] transition-colors '.
           ($muted
               ? 'text-slate-400 hover:text-slate-200'
               : 'text-slate-400 hover:text-brand-600 dark:text-slate-500 dark:hover:text-brand-300'),
   ]) }}>
    <span>Powered by</span>
    <x-brand.mark class="h-3.5 w-3.5" rounded="rounded" />
    <span class="font-semibold tracking-tight group-hover:underline">{{ config('brand.name') }}</span>
</a>
