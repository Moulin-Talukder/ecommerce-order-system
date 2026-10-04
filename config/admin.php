<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Initial Super Admin
    |--------------------------------------------------------------------------
    |
    | Credentials used by the SuperAdminSeeder to create the first Super Admin
    | account. Set these in .env, run the seeder once, then remove the
    | ADMIN_PASSWORD value from .env. The seeder never overwrites the
    | password of an account that already exists.
    |
    */

    'super_admin' => [
        'name' => env('ADMIN_NAME'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
