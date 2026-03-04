<?php

return [
    /*
    |--------------------------------------------------------------------------
    | POS Single Warehouse Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the POS will only use a single warehouse for all sales.
    | This simplifies the checkout process by not requiring warehouse selection.
    |
    */
    'single_warehouse_mode' => env('POS_SINGLE_WAREHOUSE_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Default Warehouse ID
    |--------------------------------------------------------------------------
    |
    | The default warehouse to use when single_warehouse_mode is enabled.
    | This warehouse will be automatically assigned to all POS sales.
    |
    */
    'default_warehouse_id' => env('POS_DEFAULT_WAREHOUSE_ID', 1),
];
