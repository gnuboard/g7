<?php

// G7 DevTools 엔드포인트(routes/devtools.php) 응답 문구.
// 디버그 모드에서만 동작하는 개발 도구 전용이며, MCP 도구와 브라우저 클라이언트가 소비한다.
// 응답의 `status` 값은 계약이므로 번역 대상이 아니다 — 사람이 읽는 `message` 만 여기 둔다.
return [
    // 접근 게이트
    'debug_disabled' => '디버그 모드가 비활성화되어 있습니다.',
    'connection_ok' => '연결 성공',

    // 덤프 데이터 조회 (MCP 도구용)
    'no_state' => '상태 덤프 파일이 없습니다. 브라우저에서 G7DevTools.server.dumpState()를 실행하세요.',
    'no_actions' => '액션 이력 파일이 없습니다.',
    'no_cache' => '캐시 통계 파일이 없습니다.',
    'no_change_detection' => '변경 감지 데이터가 없습니다. 브라우저에서 G7DevTools.server.dumpState()를 실행하세요.',

    // 데이터 삭제
    'cleared' => '디버그 데이터가 삭제되었습니다.',
];
