<?php

return [
    'banner' => [
        'conflict' => 'Another administrator saved changes first. Your input is preserved. Open the latest banner in another tab to compare your changes.',
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
        'attached_not_deletable' => 'An image attached to a banner cannot be deleted here. Change it on the banner edit screen and save.',
        'not_found' => 'Image not found.',
    ],
    'thumbnail' => [
        'source_label' => 'Hero section banner images',
        'list_preset_label' => 'Banner list',
    ],
    'target_setting' => [
        'fetched' => 'Target slider settings loaded.',
        'updated' => 'Saved slider settings for this target.',
        'reset' => 'Reset this target to the position defaults.',
    ],
    'position' => [
        'fetched' => 'Position settings loaded.',
        'updated' => 'Slider settings saved.',
        'not_found' => 'Position not found.',
        'targets_fetched' => 'Fetched the target list.',
        'placements_fetched' => 'Fetched the targets that have banners.',
    ],
    'console' => [
        'prune_temp_images' => [
            'retention_disabled' => 'Retention is shorter than 1 day, so temporary images are not pruned.',
            'dry_run' => ':count temporary image(s) older than :days day(s) would be pruned.',
            'done' => 'Deleted :deleted of :scanned temporary image(s) older than :days day(s) (:failed failed).',
        ],
    ],
];
