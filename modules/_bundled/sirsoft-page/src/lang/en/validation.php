<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Page Validation Messages
    |--------------------------------------------------------------------------
    */

    // slug validation messages
    'slug' => [
        'required' => 'The page slug is required.',
        'format' => 'The page slug may only contain lowercase letters, numbers, and hyphens.',
        'unique' => 'The slug is already in use.',
        'max' => 'The slug must not exceed :max characters.',
    ],

    // title validation messages
    'title' => [
        'required' => 'The page title is required.',
        'max' => 'The page title must not exceed :max characters.',
        'locale_required' => 'The title for the default language (:locale) is required.',
    ],

    // content validation messages
    'content' => [
        'max' => 'The page content is too long.',
    ],

    // content_mode validation messages
    'content_mode' => [
        'in' => 'The content mode must be either html or text.',
    ],

    // published validation messages
    'published' => [
        'boolean' => 'The published field must be true or false.',
        'required' => 'The published field is required.',
    ],

    // ids validation messages (bulk operations)
    'ids' => [
        'required' => 'Please select pages to process.',
        'array' => 'The page list format is invalid.',
        'min' => 'Please select at least :min page(s).',
        'integer' => 'Page ID must be an integer.',
        'exists' => 'Some selected pages do not exist.',
    ],

    // per_page validation messages
    'per_page' => [
        'integer' => 'The per page value must be an integer.',
        'min' => 'The per page value must be at least :min.',
        'max' => 'The per page value must not exceed :max.',
    ],

    // search validation messages
    'search' => [
        'max' => 'The search query must not exceed :max characters.',
    ],

    // search_field validation messages
    'search_field' => [
        'in' => 'The search field must be one of: all, title, slug.',
    ],

    // sort_by validation messages
    'sort_by' => [
        'in' => 'The sort field must be one of: created_at, published_at.',
    ],

    // sort_order validation messages
    'sort_order' => [
        'in' => 'The sort direction must be either asc or desc.',
    ],

    // temp_key validation messages
    'temp_key' => [
        'string' => 'The temp key format is invalid.',
        'max' => 'The temp key must not exceed :max characters.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachment Validation Messages
    |--------------------------------------------------------------------------
    */

    'attachment' => [
        'file' => [
            'required' => 'Please select a file.',
            'file' => 'The file format is invalid.',
            'max' => 'The file size must not exceed :maxKB.',
            'mimes' => 'The file type is not allowed.',
        ],
        'order' => [
            'required' => 'Order information is required.',
            'array' => 'The order format is invalid.',
        ],
    ],
];
