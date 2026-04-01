<?php

return [
    'validation' => [
        'success' => 'admin_layout.json 검증이 성공적으로 완료되었습니다.',
        'schema_failed' => 'JSON 스키마 검증에 실패했습니다: :errors',
        'unexpected_error' => '예상치 못한 오류가 발생했습니다: :message',
        'schema_not_found' => 'JSON 스키마 파일을 찾을 수 없습니다: :path',
        'schema_parse_error' => 'JSON 스키마 파일 파싱 오류: :error',
        'file_not_found' => '파일을 찾을 수 없습니다: :path',
        'json_parse_error' => 'JSON 파싱 오류 (:file): :error',
        'version_unknown' => '알 수 없는 버전',
        'version_error' => '버전 정보를 가져올 수 없습니다',
        'module_required' => '모듈명은 필수입니다',
        'module_string' => '모듈명은 문자열이어야 합니다',
        'module_format' => '모듈명은 영문자로 시작하고 영문자, 숫자, 언더스코어, 하이픈만 포함할 수 있습니다',
        'module_max_length' => '모듈명은 50자를 초과할 수 없습니다',
        
        'log' => [
            'success' => 'admin_layout.json 검증 성공',
            'failed' => 'admin_layout.json 검증 실패',
            'exception' => 'admin_layout.json 검증 중 예외 발생',
            'version_error' => '스키마 버전 정보 가져오기 실패',
        ],
    ],
    
    'loader' => [
        'validation_failed' => '모듈 :module의 레이아웃 검증에 실패했습니다: :errors',
        'file_not_found' => '레이아웃 파일을 찾을 수 없습니다: :path',
        'json_parse_error' => 'JSON 파싱 오류 (:file): :error',
        'file_load_error' => '파일 로드 중 오류가 발생했습니다 (:path): :error',
        
        'log' => [
            'cache_hit' => '캐시에서 레이아웃 데이터 로드',
            'cache_invalidated' => '캐시 무효화됨',
            'loaded' => '레이아웃 파일 로드 완료',
            'cached' => '레이아웃 데이터 캐시 저장',
            'cache_manually_invalidated' => '캐시 수동 무효화',
            'all_cache_invalidated' => '모든 캐시 무효화',
            'file_load_error' => '파일 로드 오류',
        ],
    ],
    
    'command' => [
        'starting' => 'admin_layout.json 파일 검증을 시작합니다',
        'validating_module' => '모듈 :module 검증 중...',
        'validating_all_modules' => ':count개 모듈 검증을 시작합니다',
        'module_layout_not_found' => '모듈 :module의 admin_layout.json 파일을 찾을 수 없습니다',
        'module_valid' => '모듈 :module 검증 성공',
        'module_invalid' => '모듈 :module 검증 실패: :error',
        'unexpected_error' => '모듈 :module에서 예상치 못한 오류 발생: :error',
        'modules_directory_not_found' => 'modules 디렉토리를 찾을 수 없습니다',
        'no_modules_found' => '검증할 모듈이 없습니다',
        'module_summary' => '모듈 :module 요약',
        'menu_name' => '메뉴명',
        'menu_route' => '라우트',
        'data_sources_count' => '데이터 소스 개수',
        'layout_type' => '레이아웃 타입',
        'validation_summary' => '검증 결과 요약',
        'valid_modules_count' => '유효한 모듈',
        'invalid_modules_count' => '무효한 모듈',
        'missing_layouts_count' => '레이아웃 파일 없음',
        'invalid_modules_details' => '무효한 모듈 상세:',
        'missing_layouts_details' => '레이아웃 파일이 없는 모듈:',
        'cache_stats' => '캐시 통계',
        'total_cached_modules' => '캐시된 모듈 수',
        'cache_expiration' => '캐시 만료 시간',
        'cached_modules_details' => '캐시된 모듈 상세:',
        'clearing_cache' => '캐시를 무효화하는 중...',
        'cache_cleared' => '캐시가 무효화되었습니다',
    ],
    
    // API 관련 메시지
    'success' => [
        'loaded' => 'admin_layout.json을 성공적으로 불러왔습니다',
        'cached' => '레이아웃 데이터가 캐시에 저장되었습니다',
    ],
    
    'error' => [
        'module_not_found' => '요청한 모듈을 찾을 수 없습니다',
        'json_parse_error' => 'JSON 파싱 중 오류가 발생했습니다',
        'file_not_readable' => '파일을 읽을 수 없습니다',
        'json_parse_failed' => 'JSON 파싱에 실패했습니다',
        'validation_failed' => '레이아웃 검증에 실패했습니다',
        'server_error' => '서버 내부 오류가 발생했습니다',
    ],
    
    'log' => [
        'cache_hit' => '캐시에서 레이아웃 데이터를 가져왔습니다',
        'cache_updated' => '캐시가 업데이트되었습니다',
        'layout_loaded' => '레이아웃이 성공적으로 로드되었습니다',
        'validation_completed' => '레이아웃 검증이 완료되었습니다',
        'layout_load_error' => '레이아웃 로드 중 오류가 발생했습니다',
        'validation_error' => '레이아웃 검증 중 오류가 발생했습니다',
        'service_cache_invalidated' => '서비스에서 캐시를 무효화했습니다',
        'service_all_cache_invalidated' => '서비스에서 모든 캐시를 무효화했습니다',
        'cache_invalidated' => '캐시가 무효화되었습니다',
        'all_cache_invalidated' => '모든 캐시가 무효화되었습니다',
        'file_read_error' => '파일 읽기 중 오류가 발생했습니다',
    ],
];
