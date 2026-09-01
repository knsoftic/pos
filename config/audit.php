<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How long the audit trail is kept (#94, #170)
    |--------------------------------------------------------------------------
    | Two years by default. Long enough to answer "who changed this?" about
    | anything anybody is still arguing over, short enough that the table does
    | not grow without bound on a busy shop.
    |
    | ⚠️ This governs the AUDIT TRAIL only. Nothing financial is ever pruned:
    | sales, returns, ledger entries and stock movements are corrected by an
    | opposite entry, never deleted (#133, #198). A retention policy that
    | removed those would delete the very records somebody needs when a figure
    | is questioned.
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 730),

];
