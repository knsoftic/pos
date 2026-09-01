<?php

/*
|--------------------------------------------------------------------------
| Backups (#95)
|--------------------------------------------------------------------------
| What `pos:backup` writes, where it writes it, and how long it keeps it.
|
| ⚠️ TWO THINGS THIS CONFIG CANNOT DO FOR YOU, said here because they are the
| two that actually decide whether a backup is worth anything:
|
|   1. A COPY ON THE SAME MACHINE IS NOT A BACKUP. It survives a mistake — a bad
|      migration, a wrong DELETE — and nothing else. It does not survive a disk,
|      a fire, a ransomware run or a terminated instance. Set `destination` to a
|      disk that is somewhere else (S3, a mounted volume, anything off-box), or
|      copy the files off with whatever your host provides.
|
|   2. A BACKUP NOBODY HAS RESTORED IS NOT A BACKUP, it is a file. The restore
|      command is in the README. Run it against a scratch database once, before
|      you need it, because the day you need it is the worst possible day to
|      find out the dump was truncated.
*/

return [

    /*
    | Where finished archives land. `local` keeps them under
    | storage/app/private/backups — fine for a first run and for the retention
    | logic, and not fine as the only copy. See the warning above.
    */
    'destination' => [
        'disk' => env('BACKUP_DISK', 'local'),
        'path' => env('BACKUP_PATH', 'backups'),
    ],

    /*
    | mysqldump. Its location differs per platform and it is frequently not on
    | PATH — on the XAMPP box this is built on it lives inside the XAMPP tree —
    | so it is configurable rather than assumed, and the command says exactly
    | what it looked for when it cannot find it.
    */
    'mysqldump' => env('BACKUP_MYSQLDUMP', PHP_OS_FAMILY === 'Windows'
        ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe'
        : 'mysqldump'),

    /*
    | Directories copied alongside the database. Uploads are NOT in the dump:
    | a product image or a receipt scan lives on a disk, and a database restore
    | without them leaves a catalogue full of broken pictures and an expense
    | trail with no evidence attached (#43, #149).
    */
    'include' => [
        storage_path('app/public'),
    ],

    /*
    | How many days of archives to keep.
    |
    | Deliberately longer than it feels like it needs to be. The failure that
    | most needs a backup is the one nobody noticed — a bad import, a mistaken
    | bulk edit — and those surface at stock-take or month end, weeks later. A
    | seven-day window is enough to survive a crash and not enough to survive a
    | mistake, which is the commoner disaster.
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    | Warn from `pos:preflight` when the newest archive is older than this.
    | Nightly plus a day's slack, so a single missed run is not an alarm but a
    | stopped scheduler is.
    */
    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 48),

];
