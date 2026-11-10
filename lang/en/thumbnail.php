<?php

/**
 * Image thumbnails — admin API responses, command output, source provider labels
 */
return [
    'messages' => [
        'stats_loaded' => 'Thumbnail usage loaded.',
        'purged' => 'Thumbnails cleared.',
        'pregenerate_progressed' => 'Thumbnails generated for existing images.',
    ],

    // Source provider labels (ThumbnailSourceProvider::label)
    'sources' => [
        'core_attachment' => 'Attachment images',
        'layout_attachment' => 'Layout editor images',
    ],

    // Thumbnail-only disk labels (public asset disks use the driver catalog labels)
    'disks' => [
        'thumbnails' => 'Private server storage',
    ],

    'attributes' => [
        'location' => 'storage location',
        'preset' => 'thumbnail preset',
        'cursor' => 'resume position',
        'sources' => 'source types',
        'presets' => 'thumbnail presets',
    ],

    'console' => [
        'prune' => [
            'disabled' => 'Automatic thumbnail cleanup is turned off, so nothing was run. (upload.thumbnail_prune_enabled = false)',
            'no_engine' => 'No usable image engine is available, so preset changes were not checked.',
            'disk_failed' => 'Skipped the :disk disk because it could not be read: :error',
            'dry_run' => ':total thumbnails to clean up (preset changed :stale, unknown preset :unknown, original missing :orphan, unchanged for over :days days :expired)',
            'done' => 'Deleted :total thumbnails. (preset changed :stale, unknown preset :unknown, original missing :orphan, unchanged for over :days days :expired, failed :failed)',
            'days_unset' => '-',
        ],
        'pregenerate' => [
            'unknown_source' => 'Unknown source type: :value (allowed: :allowed)',
            'unknown_preset' => 'Unknown thumbnail preset: :value (allowed: :allowed)',
            'invalid_limit' => 'The --limit value must be an integer of 1 or more (got: :value)',
            'no_engine' => 'No usable image engine is available, so thumbnails cannot be generated.',
            'nothing' => 'There are no original images to generate thumbnails for.',
            'dry_run' => ':processed original images to generate thumbnails for (:total in total)',
            'done' => 'Checked :processed original images and generated :generated new thumbnails. (:total in total)',
        ],
    ],
];
