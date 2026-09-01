<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File upload policy (#101)
    |--------------------------------------------------------------------------
    | Uploads are the classic way into an application, so the rules live in one
    | place rather than being retyped in each form request — a limit that exists
    | in four files is a limit that will disagree with itself.
    |
    | THREE THINGS PROTECT EVERY UPLOAD, and none of them trusts the browser:
    |
    |   1. The extension list below is checked by Laravel's `mimes` rule, which
    |      reads the file's actual content, not the name it arrived with.
    |   2. The `image` rule additionally requires it to decode as an image, so a
    |      PHP script renamed to .jpg is rejected before it is ever stored.
    |   3. Stored names are random (Laravel's `store()`), so a caller can never
    |      choose where a file lands or overwrite someone else's.
    |
    | Files go on the `public` disk, which is a symlink into storage — nothing
    | uploaded is ever written inside the web root itself.
    */

    'products' => [
        'disk' => env('UPLOAD_DISK', 'public'),

        // Where product images live under that disk.
        'path' => 'products',

        // WebP included because it is what phones increasingly produce; SVG is
        // deliberately absent — an SVG is a script container, not a picture.
        'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],

        'max_kb' => (int) env('UPLOAD_PRODUCT_IMAGE_MAX_KB', 2048),

        // A product photo has no business being a 10,000px poster.
        'max_dimension' => (int) env('UPLOAD_PRODUCT_IMAGE_MAX_PX', 3000),
    ],

];
