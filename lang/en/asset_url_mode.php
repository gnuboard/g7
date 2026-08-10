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
];
