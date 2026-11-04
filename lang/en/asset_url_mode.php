<?php

// 주의: 관리자 화면 문구는 여기 두지 않는다 — 템플릿 lang(admin.json)이 SSoT.
// 이 파일은 Artisan 커맨드(g7:asset-url-mode) 출력 전용이다.
return [
    // CLI status
    'current' => 'Current asset URL style: :mode',
    'table' => [
        'in_use' => 'In use',
        'alternative' => 'After switching',
    ],
    'diagnose_title' => 'To check whether your server intercepts dynamic responses, compare these two requests:',
    'diagnose_hint' => 'If only the first one fails while the second succeeds, a static-optimization rule is intercepting it. Switch to extensionless.',
    'switch_hint' => 'Switch with: php artisan g7:asset-url-mode extensionless',

    // CLI switch
    'invalid' => 'Unknown style: :mode (use extension or extensionless)',
    'already' => 'Already using the :mode style.',
    'switched' => 'Asset URL style changed from :from to :to.',
    'save_failed' => 'Failed to save the setting.',
    'seo_cache_cleared' => 'SEO cache was cleared as well (baked asset URLs used the old style).',

    // Mount prefix — what request-less contexts (queue, console, scheduler) build URLs from
    'base_path' => [
        'title' => 'Mount prefix (request-less contexts — what queue/console URLs are built from)',
        'context' => 'Prefix in this context: :prefix (the console uses the path of APP_URL in .env, or the site address path below when that is empty)',
        'configured' => 'Path of the site address: :prefix (:url — the Site URL setting, or APP_URL in .env when it is empty)',
        'mismatch' => 'The two values differ — mail and sitemap URLs built by the queue/console point outside the site. Set the path of APP_URL to the actual mount location.',
        'hint' => 'When a web request detects the mismatch, one error log ([AssetUrl]) is written per request (recorded even at the default log level, error).',
    ],
];
