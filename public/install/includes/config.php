<?php

/**
 * 그누보드7 웹 인스톨러 설정 파일
 *
 * 인스톨러의 기본 상수와 설정을 정의합니다.
 */

// 프로젝트 루트 경로 (public/install/includes에서 3단계 상위)
if (! defined('BASE_PATH')) {
    // `realpath` 도 호스팅이 막을 수 있는 함수다 (13차 환경#1). 이 줄은 인스톨러가 실행하는
    // 사실상 첫 코드라, 막힌 환경에서 무가드로 부르면 어떤 봉투도 서기 전에 치명 종료한다 —
    // 운영자에게는 백지만 남는다. 정규화는 못 해도 조립 경로로 설치는 진행된다.
    define('BASE_PATH', function_exists('realpath')
        ? (realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3))
        : dirname(__DIR__, 3));
}

// UTF-8 정규화 / JSON 출력 헬퍼 (의존성 0 — 가장 먼저 로드)
require_once __DIR__.'/utf8.php';

// 인스톨러 기본 URL
$installerPath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

// install이 없으면 추가
if (substr($installerPath, -8) !== '/install') {
    $installerPath .= '/install';
}

// 다음 define 들은 가드 필수 — 테스트 환경에서 같은 프로세스가 config.php 를 직접/간접
// 재진입(예: Laravel bootstrap + 테스트 require)할 수 있어 PHP 9 에서 fatal 이 되는
// 중복 정의 warning 을 차단.
if (! defined('INSTALLER_BASE_URL')) {
    define('INSTALLER_BASE_URL', $installerPath);
}

// 소프트웨어 최초 출시 연도 (저작권 표시용)
if (! defined('APP_RELEASE_YEAR')) {
    define('APP_RELEASE_YEAR', '2026');
}

// 최소 PHP 버전
if (! defined('MIN_PHP_VERSION')) {
    define('MIN_PHP_VERSION', '8.2.0');
}

// 필수 PHP 모듈
if (! defined('REQUIRED_EXTENSIONS')) {
    define('REQUIRED_EXTENSIONS', [
        'pdo',
        // 드라이버까지 본다 — `pdo` 만 검사하면 pdo_mysql 이 없는 환경이 요구사항을 통과한 뒤
        // DB 연결 테스트에서야 "could not find driver" 로 막힌다. G7 은 MySQL/MariaDB 만
        // 지원하므로 이 드라이버는 구동 자체의 전제다 (docs/requirements.md §1.4 「필수」).
        //
        // 근거 표기 정정 (17차): 종전 주석은 「13차 환경#21」을 인용했으나 그 번호는 계획서에도
        // 히스토리에도 없다. 존재하지 않는 근거는 다음 감사가 이 항목을 「기록 없는 변경」으로
        // 되돌리게 만든다 — 실제 근거는 위 규정 문서다.
        'pdo_mysql',
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
}

// 선택적 PHP 모듈 (설치를 권장하지만 필수는 아님)
if (! defined('OPTIONAL_EXTENSIONS')) {
    define('OPTIONAL_EXTENSIONS', [
        'zlib',     // gzip 압축 지원 (응답 압축에 사용)
        'gd',       // 이미지 처리
        'imagick',  // 고급 이미지 처리
        'redis',    // Redis 캐시 지원
        'intl',     // 국제화 지원
    ]);
}

// 최소 디스크 공간 (MB)
if (! defined('MIN_DISK_SPACE_MB')) {
    define('MIN_DISK_SPACE_MB', 500);
}

// 실사용 권장 여유 (docs/requirements.md §9 — 코어+기본 확장 실사용 약 700MB).
// 차단 기준(MIN_DISK_SPACE_MB)은 유지하고, 이 값 미만이면 경고만 덧붙인다
// (21차 #21-7, PO 결정 — 기존 통과 환경을 막지 않는다).
if (! defined('RECOMMENDED_DISK_SPACE_MB')) {
    define('RECOMMENDED_DISK_SPACE_MB', 700);
}

// DB 테이블 접두사 최대 길이.
// MySQL identifier 한도(64자) 안에서 자동 생성 인덱스명이 안전하도록 제한한다.
// 가장 긴 자동 생성 인덱스명(접두사 제외)이 58자이므로 58 + 6 = 64 로 정확히 한도에 맞는다.
// 7자 이상 접두사는 일부 인덱스명이 65자가 되어 마이그레이션이 실패한다.
if (! defined('MAX_DB_PREFIX_LENGTH')) {
    define('MAX_DB_PREFIX_LENGTH', 6);
}

// 사이트명 길이 상한 — 이 값은 `.env` 의 APP_NAME 과 설정의 site_name 으로 그대로
// 흘러가 설치 직후 관리자·사용자 화면의 입력이 된다. 인스톨러 자신은 이 값을 화면에
// 그리지 않지만, 상한이 없으면 그 뒤의 모든 면이 상한 없는 문자열을 받게 된다.
if (! defined('MAX_APP_NAME_LENGTH')) {
    define('MAX_APP_NAME_LENGTH', 100);
}

// 디렉토리 권한 설정 (8진수)
// 업계 표준 755 (WordPress/Drupal/Joomla/Laravel 공통) — 실제 통과 기준은 is_writable() && is_readable()
if (! defined('REQUIRED_DIRECTORY_PERMISSIONS')) {
    define('REQUIRED_DIRECTORY_PERMISSIONS', 0755);
}

// 권한 표시용 문자열 (사용자에게 보여줄 형식)
if (! defined('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY')) {
    define('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY', '755');
}

// 권한 검증이 필요한 디렉토리 목록
// 값이 true인 경우 하위 디렉토리까지 재귀적으로 체크
if (! defined('REQUIRED_DIRECTORIES')) {
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
        'public/build' => false,          // 초기 화면 정적 파일 게시 루트(public/build/ext)의 상위 — 설치 마지막 단계와 웹 렌더 자가 치유가 웹 계정으로 쓴다
        'lang-packs' => false,            // 언어팩 활성 디렉토리 (코어/모듈/플러그인/템플릿 lang-packs)
        'lang-packs/_pending' => false,   // 외부 ZIP/URL/GitHub 다운로드 staging
        'storage/app/core_pending' => false,  // 코어 업데이트 _pending (기본값, 커스텀 경로는 Step 3에서 설정)
    ]);
}

// 인스톨러 기본 설정값
if (! defined('DEFAULT_INSTALL_CONFIG')) {
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

        // Vendor 설치 모드 (auto|composer|bundled)
        // - auto: composer 사용 가능 시 composer, 불가 시 vendor-bundle.zip 추출
        // - composer: 강제 composer 실행
        // - bundled: 강제 vendor-bundle.zip 추출 (공유 호스팅 환경)
        'vendor_mode' => 'auto',

        // 실행 모드 (auto|subprocess|inprocess) — Step 3 선택란의 기본값 (D33)
        // - auto: 환경을 감지해 정한다 (ExecutionEnvironment)
        // - subprocess: 항상 `php artisan` 을 별도 프로세스로 실행
        // - inprocess: 웹 요청 안에서 Laravel 을 부팅해 실행 (CLI 없는 호스팅)
        // 어휘 SSoT 는 코어 App\Execution\ExecutionMode 이고, 여기에는 기본값만 둔다.
        'execution_mode' => 'auto',
    ]);
}

// 진행 기록에서 「명령 원문」을 가려내는 표식 (§12.0 W3 — 진행 기록은 사람 말로 쓴다)
//
// 진행 기록(storage/logs/installation.log)은 SSE·폴링·단계별 실행 세 모드가 공유하는 단일
// 채널이라, 채널 자체를 나누면 세 모드의 재생 경로가 전부 갈라진다. 대신 줄에 표식을 붙여
// **화면이** 분류한다 — 로그 파일에는 그대로 남아 진단이 보존되고, 화면에서는 「자세히」
// 접기 영역으로 들어간다. 서버와 화면이 같은 리터럴을 써야 하므로 회귀 테스트가 두 소스를
// 대조한다(InstallerCommandOutputChannelTest).
if (! defined('INSTALLER_COMMAND_OUTPUT_PREFIX')) {
    define('INSTALLER_COMMAND_OUTPUT_PREFIX', '[cmd] ');
}

// 설치 단계별 파일 매핑
// Step 5 (installation)에서 완료/실패/중단 화면까지 모두 처리
if (! defined('STEP_FILE_MAP')) {
    define('STEP_FILE_MAP', [
        0 => 'welcome',
        1 => 'license',
        2 => 'requirements',
        3 => 'configuration',
        4 => 'extension-selection',
        5 => 'installation',
    ]);
}

// 인스톨러 기본 상태 정의
if (! defined('DEFAULT_INSTALLATION_STATE')) {
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
            'language_packs' => [],
        ],
        'extension_names' => [], // 확장 이름 매핑 (identifier → {ko: '...', en: '...'})
    ]);
}

// 지원 언어 목록 (언어 추가 시 이 한 곳만 수정)
if (! defined('SUPPORTED_LANGUAGES')) {
    define('SUPPORTED_LANGUAGES', [
        'ko' => '한국어 (Korean)',
        'en' => 'English',
    ]);
}

// 설치 완료 후 인스톨러 파일 삭제 여부
if (! defined('DELETE_INSTALLER_AFTER_COMPLETE')) {
    define('DELETE_INSTALLER_AFTER_COMPLETE', true);
}

// 코어와 공유하는 판정 SSoT 클래스 로드.
//
// 인스톨러는 Laravel 오토로드 없이 도는 순수 PHP 이므로 파일을 직접 require 한다.
// 두 클래스는 프레임워크 의존성이 0 이라 이 방식이 성립하며, 인스톨러와 코어가
// 서로 다른 판정을 내리는 것을 구조적으로 차단한다.
// class_exists 가드: Laravel 부트가 선행된 테스트 환경에서 중복 로드 방지.
//
// 오토로드를 **막지 않는다**(2번째 인자 생략). `false` 를 주면 오토로드 가능한
// 클래스를 못 본 채 require 로 내려가는데, BASE_PATH 를 임시 디렉토리로 바꿔 두는
// 인스톨러 단위 테스트에서는 그 경로에 app/Support 가 없어 fatal 이 된다.
// 순수 인스톨러 실행 시에는 등록된 오토로더가 없어 false 를 반환하므로 require 가 그대로 돈다.
// 경로는 execution-mode.php 의 로더와 같은 규칙으로 찾는다: 판정 클래스는 이 인스톨러와 같은
// 코드 트리에 있으므로 BASE_PATH(설치 대상 루트, 테스트에서는 temp)가 아니라 이 파일 위치를
// 기준으로 삼고, 트리 밖에 복제된 경우만 BASE_PATH 로 폴백한다. 두 지점이 서로 다른 규칙을
// 쓰면 같은 클래스를 서로 다른 파일에서 찾게 된다.
//
// 이 블록의 네 클래스는 **같은 규칙**을 쓴다 — 일부만 BASE_PATH 직접 조립으로 남기면
// 다음 사람이 어느 쪽을 따라야 할지 알 수 없고, 오토로더가 없고 BASE_PATH 가 코드 트리
// 밖을 가리키는 조합에서 그 일부만 fatal 이 된다.
$g7SharedClassRoot = dirname(__DIR__, 3);
if (! is_file($g7SharedClassRoot.'/app/Support/ExecutionEnvironment.php')) {
    $g7SharedClassRoot = BASE_PATH;
}
foreach ([
    'App\\Support\\PrivilegedDatabaseAccounts' => '/app/Support/PrivilegedDatabaseAccounts.php',
    'App\\Support\\OpcacheStatus' => '/app/Support/OpcacheStatus.php',
    // 실행 모드 판정 SSoT — PhpBinaryResolver 는 ExecutionEnvironment 의 probe 위임 대상이라 함께 로드
    'App\\Support\\PhpBinaryResolver' => '/app/Support/PhpBinaryResolver.php',
    'App\\Support\\ExecutionEnvironment' => '/app/Support/ExecutionEnvironment.php',
] as $g7SharedClass => $g7SharedClassFile) {
    if (! class_exists($g7SharedClass)) {
        require_once $g7SharedClassRoot.$g7SharedClassFile;
    }
}
unset($g7SharedClassRoot, $g7SharedClass, $g7SharedClassFile);
