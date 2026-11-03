<?php

return [
    'action' => [
        'popup' => [
            'create' => 'Create popup',
            'update' => 'Update popup',
            'delete' => 'Delete popup',
            'bulk_status' => 'Bulk show/hide popups',
            'bulk_copy' => 'Bulk copy popups',
            'bulk_delete' => 'Bulk delete popups',
            'reorder' => 'Reorder popups',
            'image_update' => 'Replace popup image',
            'image_remove' => 'Remove popup image',
        ],
    ],
    'description' => [
        'popup_create' => 'Created popup ":popup_title".',
        'popup_update' => 'Updated popup ":popup_title".',
        'popup_delete' => 'Deleted popup ":popup_title".',
        'popup_bulk_show' => 'Set popup ":popup_title" to shown.',
        'popup_bulk_hide' => 'Set popup ":popup_title" to hidden.',
        'popup_bulk_copy' => 'Created ":popup_title" by copying a popup (source ID: :source_id).',
        'popup_reorder' => 'Reordered :count popups.',
        'popup_image_update' => 'Replaced the :device image of popup ":popup_title".',
        'popup_image_remove' => 'Removed the :device image of popup ":popup_title".',
    ],
    'fields' => [
        'title' => 'Admin title',
        'is_active' => 'Enabled',
        'content_type' => 'Content type',
        'display_period' => 'Display period',
        'starts_at' => 'Start',
        'ends_at' => 'End',
        'page_scope' => 'Pages',
        'audience' => 'Audience',
        'device' => 'Device',
        'link_url' => 'Link URL',
    ],
];
