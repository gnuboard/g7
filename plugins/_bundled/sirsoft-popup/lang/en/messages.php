<?php

return [
    'popup' => [
        'conflict' => 'Another administrator saved this popup first. Your input is kept. Compare it with the latest popup and save again.',
        'fetched' => 'Popups loaded.',
        'created' => 'Popup created.',
        'updated' => 'Popup updated.',
        'deleted' => 'Popup deleted.',
        'upload_failed' => 'The image could not be saved. Please upload it again in a moment. If this keeps happening, check the server storage space.',
        'reordered' => 'Popup order changed.',
        'bulk_status' => 'Changed visibility of :count popups.',
        'bulk_copied' => 'Copied :count popups. Copies were added inactive at the end.',
        'bulk_copied_partial' => 'Copied :count popups; :failed could not be copied. Check the image files and try again.',
        'bulk_deleted' => 'Deleted :count popups.',
        'copy_suffix' => ' (copy)',
    ],
    'image' => [
        'uploaded' => 'Image uploaded.',
        'deleted' => 'Image deleted.',
        'upload_failed' => 'Could not save the image. Upload it again shortly. If this continues, check the server storage space.',
        'delete_failed' => 'Could not delete the image file. Try again shortly.',
        'attached_not_deletable' => 'Images attached to a popup cannot be deleted here. Replace it on the popup edit screen and save.',
        'not_owned' => 'Temporary images uploaded by another administrator cannot be deleted. Unsaved images are removed by the cleanup schedule.',
        'not_found' => 'Image not found.',
    ],
    'thumbnail' => [
        'source_label' => 'Popup images',
    ],
    'settings' => [
        'fetched' => 'Display settings loaded.',
    ],
    'console' => [
        'prune_temp_images' => [
            'retention_disabled' => 'Retention is shorter than 1 day, so temporary images are not pruned.',
            'dry_run' => ':count temporary images older than :days days would be pruned.',
            'done' => 'Deleted :deleted of :scanned temporary images older than :days days (:failed failed).',
        ],
    ],
];
