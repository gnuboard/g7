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

    // 화면 구성에 필요한 스크립트를 끝내 불러오지 못했을 때 (코어 엔진 / 템플릿 컴포넌트 번들)
    'bootstrap' => [
        'title' => 'Failed to load the page',
        'message' => 'Your network connection may be unstable. Please refresh and try again.',
        'reload' => 'Refresh',

        // 스크립트를 받았으나 브라우저가 실행하지 못한 경우 (지원 범위보다 오래된 브라우저 등).
        // 새로고침해도 낫지 않으므로 이 분기에서는 새로고침 버튼을 렌더하지 않는다.
        'incompatible_title' => 'This browser cannot display the page',
        'incompatible_message' => 'Your browser is too old to run this site. Please update it to the latest version, or try a different browser.',

        // The HTTPS page requested an http:// asset and the browser blocked it (running behind a
        // reverse proxy without trusted proxies configured, #124). Ordinary visitors see this
        // screen, but only the operator can act on it, so the screen carries no server-side
        // instructions — the cause and the fix go to the console for the operator. Reloading does
        // not help, so no button is rendered and no natural recovery is implied.
        'blocked_title' => 'Something went wrong with this site',
        'blocked_message' => 'The site cannot be displayed because of a configuration problem. This is not caused by anything on your side — the site operator needs to fix it.',
    ],
];
