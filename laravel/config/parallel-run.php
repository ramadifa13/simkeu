<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Native Reference Database
    |--------------------------------------------------------------------------
    |
    | Laravel must never use the native production database as its default
    | write connection during the parallel-run period.
    |
    */
    'native_reference_database' => env('NATIVE_REFERENCE_DATABASE'),
];
