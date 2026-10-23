<?php

return [
    // 정적 훅 매핑 캐시 (hooks:cache / hooks:clear)
    'cache_generated' => '정적 훅 매핑 캐시를 생성했습니다. (코어 :core / 모듈 :modules / 플러그인 :plugins) → :path',
    'cache_cleared' => '정적 훅 매핑 캐시를 삭제했습니다.',
    'cache_absent' => '삭제할 정적 훅 매핑 캐시가 없습니다.',

    // 훅 캐시 읽기·생성 운영 로그 (부팅 중 남는 경고는 언어팩 등록 뒤 기록된다)
    'cache_load_failed' => '훅 캐시 파일 로드 실패 — 스캔 폴백',
    'cache_invalid_structure' => '훅 캐시 파일 구조가 올바르지 않습니다 — 스캔 폴백',
    'cache_stale' => '훅 캐시 파일의 형식·코어 버전이 지금과 다릅니다 — 스캔 폴백 (php artisan hooks:cache 로 다시 만드세요)',
    'cache_file_written' => '훅 매핑 캐시 파일 생성 완료',
    'cache_listener_not_found' => '훅 캐시 생성: 리스너 클래스를 찾을 수 없습니다',
    'cache_listener_hooks_failed' => '훅 캐시 생성: getSubscribedHooks() 오류 — 항목 제외',
    'cache_listener_interface_missing' => '훅 캐시 생성: 리스너가 HookListenerInterface 를 구현하지 않아 제외합니다',
    'cache_stale_extension_changed' => '활성 확장의 파일이 바뀌어 훅 캐시가 낡았습니다 — 스캔 폴백 후 이 요청 끝에 다시 만듭니다',
];
