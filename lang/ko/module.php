<?php

return [
    // 모듈 관리 메시지
    'not_found' => '모듈 :module을(를) 찾을 수 없습니다.',
    'dependency_not_active' => '의존성 모듈 :dependency이(가) 설치되지 않았거나 활성화되지 않았습니다.',
    
    // 모듈 작업 메시지
    'fetch_success' => '모듈을 성공적으로 가져왔습니다.',
    'fetch_failed' => '모듈을 가져오는데 실패했습니다: :error',
    'install_success' => '모듈이 성공적으로 설치되었습니다.',
    'install_failed' => '모듈 설치에 실패했습니다: :error',
    'preview_success' => 'manifest 미리보기를 완료했습니다.',
    'preview_failed' => 'manifest 미리보기에 실패했습니다: :error',
    'uninstall_success' => '모듈이 성공적으로 제거되었습니다.',
    'uninstall_failed' => '모듈 제거에 실패했습니다: :error',
    'activate_success' => '모듈이 성공적으로 활성화되었습니다.',
    'activate_failed' => '모듈 활성화에 실패했습니다: :error',
    'deactivate_success' => '모듈이 성공적으로 비활성화되었습니다.',
    'deactivate_failed' => '모듈 비활성화에 실패했습니다: :error',
    'update_success' => '모듈이 성공적으로 업데이트되었습니다.',
    'update_failed' => '모듈 업데이트에 실패했습니다: :error',
    'refresh_layouts_success' => '모듈 레이아웃이 성공적으로 갱신되었습니다.',
    'refresh_layouts_failed' => '모듈 레이아웃 갱신에 실패했습니다: :error',
    'uninstall_info_success' => '모듈 삭제 정보를 성공적으로 조회했습니다.',
    'uninstall_info_failed' => '모듈 삭제 정보 조회에 실패했습니다: :error',
    'dependent_templates_success' => '의존 템플릿 정보를 성공적으로 조회했습니다.',
    'dependent_templates_failed' => '의존 템플릿 정보 조회에 실패했습니다: :error',
    'license_not_found' => '모듈 라이선스 파일을 찾을 수 없습니다.',

    // 상태
    'status' => [
        'active' => '활성',
        'inactive' => '비활성',
        'installing' => '설치 중',
        'uninstalling' => '제거 중'
    ],
    
    // 오류 메시지
    'errors' => [
        'module_class_not_found' => '모듈 클래스를 찾을 수 없습니다.',
        'migration_failed' => '마이그레이션 실행에 실패했습니다: :error',
        'dependency_check_failed' => '의존성 확인에 실패했습니다: :error',
        'database_error' => '데이터베이스 오류가 발생했습니다.',
        'module_not_found' => '모듈 :name을(를) 찾을 수 없습니다.',
        'module_not_active' => '모듈 :name이(가) 활성화되어 있지 않습니다.',
    ]
];
