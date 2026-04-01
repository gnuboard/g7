<?php

/**
 * 그누보드7 웹 인스톨러 설정 파일
 *
 * 인스톨러의 기본 상수와 설정을 정의합니다.
 *
 * @package G7\Installer
 */

// 프로젝트 루트 경로 (public/install/includes에서 3단계 상위)
if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}

// 인스톨러 기본 URL 
$installerPath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

// install이 없으면 추가
if (substr($installerPath, -8) !== '/install') {
    $installerPath .= '/install';
}

define('INSTALLER_BASE_URL', $installerPath);

// 인스톨러 버전
define('INSTALLER_VERSION', '1.0.0');

// 소프트웨어 최초 출시 연도 (저작권 표시용)
define('APP_RELEASE_YEAR', '2026');

// 최소 PHP 버전
define('MIN_PHP_VERSION', '8.2.0');

// 필수 PHP 모듈
define('REQUIRED_EXTENSIONS', [
    'pdo',
    'mbstring',
    'openssl',
    'tokenizer',
    'xml',
    'ctype',
    'json',
    'fileinfo',
    'curl',
    'dom',
    'filter',
    'hash',
    'pcre',
    'session',
]);

// 선택적 PHP 모듈 (설치를 권장하지만 필수는 아님)
define('OPTIONAL_EXTENSIONS', [
    'zlib',     // gzip 압축 지원 (응답 압축에 사용)
    'gd',       // 이미지 처리
    'imagick',  // 고급 이미지 처리
    'redis',    // Redis 캐시 지원
    'intl',     // 국제화 지원
]);

// 최소 디스크 공간 (MB)
define('MIN_DISK_SPACE_MB', 500);

// 디렉토리 권한 설정 (8진수)
// chown + chmod 모델: 소유자(www-data) rwx, 그룹(www-data) rwx, 기타 ---
define('REQUIRED_DIRECTORY_PERMISSIONS', 0770);

// 권한 표시용 문자열 (사용자에게 보여줄 형식)
define('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY', '770');

// 권한 검증이 필요한 디렉토리 목록
// 값이 true인 경우 하위 디렉토리까지 재귀적으로 체크
define('REQUIRED_DIRECTORIES', [
    'storage' => true,
    'bootstrap/cache' => false,
    'vendor' => false,
    'modules' => false,
    'modules/_pending' => false,
    'plugins' => false,
    'plugins/_pending' => false,
    'templates' => false,
    'templates/_pending' => false,
    'storage/app/core_pending' => false,  // 코어 업데이트 _pending (기본값, 커스텀 경로는 Step 3에서 설정)
]);

// 인스톨러 기본 설정값
define('DEFAULT_INSTALL_CONFIG', [
    // Write DB 설정
    'db_write_host' => 'localhost',
    'db_write_port' => '3306',
    'db_write_database' => '',
    'db_write_username' => '',
    'db_write_password' => '',
    'db_prefix' => 'g7_',

    // Read DB 설정
    'use_read_db' => false,
    'db_read_host' => 'localhost',
    'db_read_port' => '3306',
    'db_read_database' => '',
    'db_read_username' => '',
    'db_read_password' => '',

    // 사이트 설정
    'app_name' => '그누보드7',
    'app_env' => 'production',

    // 관리자 계정 설정
    'admin_name' => '관리자',
    'admin_email' => '',
    'admin_password' => '',
    'admin_language' => 'ko',

    // 코어 업데이트 설정
    'core_update_pending_path' => '',  // 빈 값 = 기본값(storage/app/core_pending) 사용
    'core_update_github_url' => 'https://github.com/gnuboard/g7',
    'core_update_github_token' => '',  // GitHub Personal Access Token (프라이빗 저장소용)

    // PHP CLI / Composer 경로 설정
    'php_binary' => 'php',        // PHP CLI 바이너리 경로 (예: /usr/local/php82/bin/php)
    'composer_binary' => '',      // Composer 바이너리 경로 (빈 값 = 시스템 PATH 사용)
]);

// 설치 단계별 파일 매핑
// Step 5 (installation)에서 완료/실패/중단 화면까지 모두 처리
define('STEP_FILE_MAP', [
    0 => 'welcome',
    1 => 'license',
    2 => 'requirements',
    3 => 'configuration',
    4 => 'extension-selection',
    5 => 'installation',
]);

// 인스톨러 기본 상태 정의
define('DEFAULT_INSTALLATION_STATE', [
    'current_step' => 0,
    'step_status' => [
        '0' => 'pending',
        '1' => 'pending',
        '2' => 'pending',
        '3' => 'pending',
        '4' => 'pending',
        '5' => 'pending',
    ],
    'completed_tasks' => [],
    'current_task' => null,
    'current_task_name' => null,
    'installation_status' => 'not_started',
    'config' => [],
    'selected_extensions' => [
        'admin_templates' => [],
        'user_templates' => [],
        'modules' => [],
        'plugins' => [],
    ],
    'extension_names' => [], // 확장 이름 매핑 (identifier → {ko: '...', en: '...'})
]);

// 지원 언어 목록 (언어 추가 시 이 한 곳만 수정)
define('SUPPORTED_LANGUAGES', [
    'ko' => '한국어 (Korean)',
    'en' => 'English',
]);

// 설치 완료 후 인스톨러 파일 삭제 여부
define('DELETE_INSTALLER_AFTER_COMPLETE', true);