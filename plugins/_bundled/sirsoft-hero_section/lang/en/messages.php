<?php

return [
    'banner' => [
        'fetched' => 'Banners loaded.',
        'created' => 'Banner created.',
        'updated' => 'Banner updated.',
        'deleted' => 'Banner deleted.',
        'reordered' => 'Banner order changed.',
        'bulk_status' => 'Changed visibility of :count banner(s).',
        'bulk_deleted' => 'Deleted :count banner(s).',
    ],
    'image' => [
        'uploaded' => 'Image uploaded.',
        'deleted' => 'Image deleted.',
        'delete_failed' => 'Could not delete the image file. Please try again later.',
        'pc_not_deletable' => 'The PC image is required and cannot be deleted. Upload a new image to replace it.',
        'not_found' => 'Image not found.',
    ],
    'position' => [
        'fetched' => 'Position settings loaded.',
        'updated' => 'Slider settings saved.',
        'not_found' => 'Position not found.',
    ],
    'console' => [
        'prune_temp_images' => [
            'retention_disabled' => 'Retention is shorter than 1 day, so temporary images are not pruned.',
            'dry_run' => ':count temporary image(s) older than :days day(s) would be pruned.',
            'done' => 'Deleted :deleted of :scanned temporary image(s) older than :days day(s) (:failed failed).',
        ],
    ],
];
