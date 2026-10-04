<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // Files are served from public/uploads. The directory ships with an
    // .htaccess that refuses to execute anything, so a file that somehow got
    // through validation still cannot run.
    'root_path' => Env::get('UPLOAD_ROOT_PATH', APP_ROOT . '/public/uploads'),
    'public_prefix' => Env::get('UPLOAD_PUBLIC_PREFIX', 'uploads'),

    'max_image_bytes' => Env::int('UPLOAD_MAX_IMAGE_BYTES', 5_242_880),
    'min_image_edge_px' => Env::int('UPLOAD_MIN_IMAGE_EDGE_PX', 200),
    'max_image_edge_px' => Env::int('UPLOAD_MAX_IMAGE_EDGE_PX', 6000),
    'max_images_per_product' => Env::int('UPLOAD_MAX_IMAGES_PER_PRODUCT', 10),
];
