<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Error Page Translations
    |--------------------------------------------------------------------------
    */

    'back_home' => 'Back to Home',

    '401' => [
        'title' => 'Authentication Required',
        'message' => 'You need to log in to access this page.',
    ],

    '403' => [
        'title' => 'Access Denied',
        'message' => 'You do not have permission to access this page.',
    ],

    '404' => [
        'title' => 'Page Not Found',
        'message' => 'The page you requested does not exist or has been moved.',
    ],

    '500' => [
        'title' => 'Server Error',
        'message' => 'Something went wrong while processing your request. Please try again later.',
    ],

    '503' => [
        'title' => 'Service Unavailable',
        'message' => 'The service is currently unavailable. Please try again later.',
        'unmet_dependencies' => 'Unmet Dependencies',
        'template' => 'Template',
        'modules' => 'Modules',
        'plugins' => 'Plugins',
        'contact_admin' => 'If the problem persists, please contact the administrator.',
    ],
];
