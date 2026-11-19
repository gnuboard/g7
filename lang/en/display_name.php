<?php

return [
    // Display name kinds
    'kinds' => [
        'name' => 'Name',
        'nickname' => 'Nickname',
        'masked_name' => 'Masked name',
        'masked_id' => 'Masked ID',
        'name_nickname' => 'Name (nickname)',
    ],

    // Display name areas
    'areas' => [
        'site' => 'Site default',
        'admin' => 'Admin screens',
        'notification' => 'Notifications',
    ],

    // Area modes
    'modes' => [
        'inherit' => 'Follow parent area',
        'fixed' => 'Fixed kind',
        'member' => 'Chosen by member',
    ],

    // Sample member used for previews
    'sample' => [
        'name' => 'John Smith',
        'nickname' => 'Johnny',
    ],

    // Reason the nickname field is locked
    'locks' => [
        'nickname_display' => 'This cannot be changed because the display name settings use the nickname. Remove the kinds that use the nickname from the display name settings to change it again.',
    ],

    // Where a custom field is used as a display name (shown when deletion is blocked)
    'usages' => [
        'selectable_kind' => 'Display name kinds members can choose',
        'member_default_kind' => 'Default display name kind for members',
        'area' => 'Display name of the :area area',
        'area_instance' => ':area: :instance',
    ],

    'validation' => [
        'not_permitted' => 'You do not have permission to choose a display name kind.',
        'not_selectable' => 'This display name kind cannot be chosen. Please choose again.',
        'unknown_kind' => 'This display name kind is not available: :kind',
        'unknown_area' => 'This display name area is not available: :area',
        'default_not_selectable' => 'The default kind for members must be one of the kinds members can choose.',
        'site_cannot_inherit' => 'The site default area has no parent area to follow. Fix a kind or let members choose.',
        'fixed_kind_required' => 'Choose the display name kind to fix for the :area area.',
    ],

    'attributes' => [
        'preview_name' => 'preview name',
        'preview_nickname' => 'preview nickname',
        'preview_email' => 'preview email',
    ],
];
