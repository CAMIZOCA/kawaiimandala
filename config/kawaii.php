<?php

return [

    'default_author' => env('KAWAII_DEFAULT_AUTHOR', 'Marvin Baptista'),

    'default_mandala_count' => (int) env('KAWAII_DEFAULT_MANDALA_COUNT', 22),
    'min_mandalas' => 1,
    'max_mandalas' => 60,

    // Print size (KDP interior, no bleed). 1 inch = 25.4 mm.
    'page_mm' => 215.9,
    'margin_mm' => 12.7,
    'usable_mm' => 190.5,

    // Image rules: 7.5 in usable area at 300 DPI = 2250 px.
    'min_image_px' => 2250,
    'target_image_px' => 2550,
    'max_upload_kb' => 30720,
    'allow_low_res_export' => (bool) env('KAWAII_ALLOW_LOW_RES_EXPORT', false),

    // Images returned by Activepieces below target_image_px are padded to a square,
    // upscaled to target_image_px and cleaned to crisp black-on-white line art.
    'auto_upscale' => (bool) env('KAWAII_AUTO_UPSCALE', true),

    'activepieces' => [
        'enabled' => (bool) env('ACTIVEPIECES_ENABLED', false),
        'shared_secret' => env('ACTIVEPIECES_SHARED_SECRET'),
        'public_url' => env('APP_PUBLIC_URL'),
        'style_profile' => 'kawaii_mandala_v1',
        'request_timeout_seconds' => 15,
        // A "requested" slot may be re-requested after this many minutes.
        'stale_after_minutes' => 10,
        'download_timeout_seconds' => 60,
    ],

];
