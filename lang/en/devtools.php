<?php

// G7 DevTools 엔드포인트(routes/devtools.php) 응답 문구.
// 디버그 모드에서만 동작하는 개발 도구 전용이며, MCP 도구와 브라우저 클라이언트가 소비한다.
// 응답의 `status` 값은 계약이므로 번역 대상이 아니다 — 사람이 읽는 `message` 만 여기 둔다.
return [
    // Access gate
    'debug_disabled' => 'Debug mode is disabled.',
    'connection_ok' => 'Connected',

    // Dump data lookup (for MCP tools)
    'no_state' => 'No state dump file found. Run G7DevTools.server.dumpState() in the browser.',
    'no_actions' => 'No action history file found.',
    'no_cache' => 'No cache statistics file found.',
    'no_change_detection' => 'No change detection data found. Run G7DevTools.server.dumpState() in the browser.',

    // Data removal
    'cleared' => 'Debug data has been cleared.',
];
