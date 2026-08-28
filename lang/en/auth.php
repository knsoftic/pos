<?php

/*
|--------------------------------------------------------------------------
| Authentication Language Lines
|--------------------------------------------------------------------------
| Used by LoginRequest / AdminLoginController for failed-login and
| rate-limit (throttle) messages. Kept in a lang file so nothing is
| hardcoded in controllers. #190 (no hardcoding).
*/

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
];
