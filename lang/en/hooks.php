<?php

return [
    // Static hook mapping cache (hooks:cache / hooks:clear)
    'cache_generated' => 'Static hook mapping cache generated. (core :core / modules :modules / plugins :plugins) → :path',
    'cache_cleared' => 'Static hook mapping cache cleared.',
    'cache_absent' => 'No static hook mapping cache to clear.',

    // 훅 캐시 읽기·생성 운영 로그 (부팅 중 남는 경고는 언어팩 등록 뒤 기록된다)
    'cache_load_failed' => 'Failed to load the hook cache file — falling back to scanning',
    'cache_invalid_structure' => 'The hook cache file structure is invalid — falling back to scanning',
    'cache_stale' => 'The hook cache file format or core version differs from the current one — falling back to scanning (rebuild it with php artisan hooks:cache)',
    'cache_file_written' => 'Hook mapping cache file generated',
    'cache_listener_not_found' => 'Hook cache generation: listener class not found',
    'cache_listener_hooks_failed' => 'Hook cache generation: getSubscribedHooks() error — entry excluded',
];
