<?php

return [
    'action' => [
        'hero_banner' => [
            'create' => 'Hero banner created',
            'update' => 'Hero banner updated',
            'delete' => 'Hero banner deleted',
            'bulk_status' => 'Hero banners shown/hidden in bulk',
            'bulk_delete' => 'Hero banners deleted in bulk',
            'reorder' => 'Hero banners reordered',
            'image_update' => 'Hero banner image replaced',
            'image_remove' => 'Hero banner image removed',
        ],
        'hero_position' => [
            'update' => 'Hero slider settings changed',
        ],
    ],
    'description' => [
        'hero_banner_create' => 'Created hero banner ":banner_title".',
        'hero_banner_update' => 'Updated hero banner ":banner_title".',
        'hero_banner_delete' => 'Deleted hero banner ":banner_title".',
        'hero_banner_bulk_show' => 'Set hero banner ":banner_title" to visible.',
        'hero_banner_bulk_hide' => 'Set hero banner ":banner_title" to hidden.',
        'hero_banner_reorder' => 'Reordered :count hero banner(s) in position :position.',
        'hero_banner_image_update' => 'Replaced the :device image of hero banner ":banner_title".',
        'hero_banner_image_remove' => 'Removed the :device image of hero banner ":banner_title".',
        'hero_position_update' => 'Changed slider settings for hero position :position.',
    ],
    'fields' => [
        'position' => 'Position',
        'is_active' => 'Active',
        'display_period' => 'Display period',
        'starts_at' => 'Starts at',
        'ends_at' => 'Ends at',
        'title' => 'Title',
        'link_url' => 'Link URL',
        'autoplay' => 'Autoplay',
        'interval_seconds' => 'Interval (seconds)',
        'effect' => 'Effect',
        'width' => 'Width',
    ],
];
