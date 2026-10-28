<?php

return [
    'link_url' => [
        'unsafe' => 'Links must be a site path starting with / or an http(s) address. Addresses starting with // and schemes such as javascript: are not allowed.',
    ],
    'routes' => [
        'invalid' => '":route" is not a valid path. Enter a path that starts with / and has no ?, #, *, spaces, // or ..',
    ],
    'order' => [
        'incomplete' => 'Send every popup when reordering. Reload the list and try again.',
    ],
    'body_html' => [
        'emptied_by_sanitize' => 'The :locale content only has elements that are not allowed (iframe, script, form, etc.) and would be empty when saved. Add paragraphs, images, tables or other content.',
    ],
    'pc_image' => [
        'required' => 'Image popups need a PC image.',
    ],
    'image_selection' => [
        'invalid' => 'The selected image could not be found. Upload it again and save.',
    ],
    'file' => [
        'server_limit' => 'The file exceeds the server upload limit (:mbMB). Upload a smaller file or ask the server administrator to raise the PHP upload limit.',
        'max' => 'Images must be :mbMB or smaller.',
        'mimes' => 'Only JPG, PNG, WEBP and GIF images can be uploaded.',
        'dimensions' => 'Image width and height must be 4000 pixels or less.',
    ],
    'attributes' => [
        'revision' => 'revision',
        'pc_image' => 'PC image',
        'mobile_image' => 'mobile image',
        'status' => 'status',
        'device' => 'device',
        'search' => 'search term',
        'sort_by' => 'sort field',
        'sort_order' => 'sort direction',
        'page' => 'page',
        'per_page' => 'items per page',
        'title' => 'admin title',
        'is_active' => 'enabled',
        'content_type' => 'content type',
        'display_period' => 'display period',
        'starts_at' => 'start',
        'ends_at' => 'end',
        'page_scope' => 'pages',
        'routes' => 'paths',
        'route_item' => 'path',
        'audience' => 'audience',
        'image_alt' => 'image alt text',
        'link_url' => 'link URL',
        'link_new_window' => 'open in new window',
        'body_html' => 'body',
        'temp_key' => 'temporary upload key',
        'file' => 'image file',
        'ids' => 'selected popups',
        'ids_item' => 'selected popup',
    ],
];
