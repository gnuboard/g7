<?php
/**
 * 인스톨러 Windows 명령어 대응 독립 테스트 스크립트
 *
 * vendor 없이 실행 가능한 단순 테스트 러너입니다.
 * 실행: php tests/Unit/Installer/run_test.php
 */

// 인스톨러 설정 로드
define('BASE_PATH', dirname(__DIR__, 3));
define('REQUIRED_DIRECTORY_PERMISSIONS', 0770);
define('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY', '770');
define('REQUIRED_DIRECTORIES', ['storage' => true]);
define('SUPPORTED_LANGUAGES', ['ko' => '한국어', 'en' => 'English']);
define('INSTALLER_BASE_URL', '/install');

require_once BASE_PATH . '/public/install/includes/functions.php';

$passed = 0;
$failed = 0;
$errors = [];

function assert_true($condition, $message) {
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  ✓ {$message}\n";
    } else {
        $failed++;
        $errors[] = $message;
        echo "  ✗ {$message}\n";
    }
}

echo "=== 인스톨러 Windows 명령어 대응 테스트 ===\n\n";

// 1. isWindows() 테스트
echo "[1] isWindows() 함수 테스트\n";
$isWin = isWindows();
assert_true(is_bool($isWin), 'isWindows()는 bool을 반환해야 합니다');
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    assert_true($isWin === true, 'Windows 환경에서 true를 반환해야 합니다');
} else {
    assert_true($isWin === false, 'Linux/Mac 환경에서 false를 반환해야 합니다');
}

// 2. getEnvCopyCommand() 테스트 - basePath 있음
echo "\n[2] getEnvCopyCommand() 테스트 (basePath 있음)\n";
$cmd = getEnvCopyCommand('/var/www/g7');
assert_true(strpos($cmd, '.env.example') !== false, '명령어에 .env.example이 포함되어야 합니다');
assert_true(strpos($cmd, '.env') !== false, '명령어에 .env이 포함되어야 합니다');
if ($isWin) {
    assert_true(strpos($cmd, 'copy') === 0, 'Windows에서 copy 명령어로 시작해야 합니다');
    assert_true(strpos($cmd, '\\') !== false, 'Windows에서 백슬래시 경로를 사용해야 합니다');
} else {
    assert_true(strpos($cmd, 'cp') === 0, 'Linux에서 cp 명령어로 시작해야 합니다');
}

// 3. getEnvCopyCommand() 테스트 - basePath 없음
echo "\n[3] getEnvCopyCommand() 테스트 (basePath 없음)\n";
$cmd2 = getEnvCopyCommand('');
if ($isWin) {
    assert_true(strpos($cmd2, 'copy ') === 0, 'Windows에서 copy로 시작해야 합니다');
} else {
    assert_true(strpos($cmd2, 'cp ') === 0, 'Linux에서 cp로 시작해야 합니다');
}
assert_true(strpos($cmd2, '.env.example') !== false, '.env.example이 포함되어야 합니다');

// 4. getPermissionFixCommand() 테스트 - ownership 모드
echo "\n[4] getPermissionFixCommand() 테스트 (ownership 모드)\n";
$permCmd = getPermissionFixCommand('www-data', '/var/www/storage', 'ownership');
if ($isWin) {
    assert_true(strpos($permCmd, 'icacls') !== false, 'Windows에서 icacls 명령어를 사용해야 합니다');
    assert_true(strpos($permCmd, 'Everyone:(OI)(CI)F') !== false, 'Windows에서 Everyone 권한을 부여해야 합니다');
    assert_true(strpos($permCmd, '/T') !== false, 'Windows에서 /T 플래그(재귀)를 사용해야 합니다');
    assert_true(strpos($permCmd, '\\var\\www\\storage') !== false, 'Windows에서 백슬래시 경로를 사용해야 합니다');
} else {
    assert_true(strpos($permCmd, 'sudo chgrp -R www-data') !== false, 'Linux에서 chgrp 명령어를 사용해야 합니다');
    assert_true(strpos($permCmd, 'sudo chmod -R 2770') !== false, 'Linux에서 chmod 2770을 사용해야 합니다');
}

// 5. getPermissionFixCommand() 테스트 - file 모드
echo "\n[5] getPermissionFixCommand() 테스트 (file 모드)\n";
$filePermCmd = getPermissionFixCommand('www-data', '/var/www/.env', 'file');
if ($isWin) {
    assert_true(strpos($filePermCmd, 'icacls') !== false, 'Windows에서 icacls 명령어를 사용해야 합니다');
    assert_true(strpos($filePermCmd, 'Everyone:F') !== false, 'Windows에서 Everyone:F 권한을 부여해야 합니다');
    assert_true(strpos($filePermCmd, '/T') === false, 'file 모드에서는 /T 플래그를 사용하지 않아야 합니다');
} else {
    assert_true(strpos($filePermCmd, 'sudo chgrp www-data') !== false, 'Linux에서 chgrp 명령어를 사용해야 합니다');
    assert_true(strpos($filePermCmd, 'sudo chmod 660') !== false, 'Linux에서 chmod 660을 사용해야 합니다');
}

// 6. 다국어 파일 번역 키 테스트
echo "\n[6] 다국어 파일 번역 키 테스트\n";
$koTranslations = require BASE_PATH . '/public/install/lang/ko.php';
$enTranslations = require BASE_PATH . '/public/install/lang/en.php';
assert_true(isset($koTranslations['permission_windows_hint']), 'ko.php에 permission_windows_hint 키가 존재해야 합니다');
assert_true(isset($enTranslations['permission_windows_hint']), 'en.php에 permission_windows_hint 키가 존재해야 합니다');
assert_true(!empty($koTranslations['permission_windows_hint']), 'ko.php permission_windows_hint 값이 비어있지 않아야 합니다');
assert_true(!empty($enTranslations['permission_windows_hint']), 'en.php permission_windows_hint 값이 비어있지 않아야 합니다');
assert_true(isset($koTranslations['directory_create_guide']), 'ko.php에 directory_create_guide 키가 존재해야 합니다');
assert_true(isset($enTranslations['directory_create_guide']), 'en.php에 directory_create_guide 키가 존재해야 합니다');
assert_true(!empty($koTranslations['directory_create_guide']), 'ko.php directory_create_guide 값이 비어있지 않아야 합니다');
assert_true(!empty($enTranslations['directory_create_guide']), 'en.php directory_create_guide 값이 비어있지 않아야 합니다');

// 7. check-configuration.php의 .env 명령어가 OS에 맞게 생성되는지 테스트
echo "\n[7] getEnvCopyCommand()가 check-configuration.php에서 사용되는 패턴 테스트\n";
$basePath = BASE_PATH;
$envCmd = getEnvCopyCommand($basePath);
if ($isWin) {
    assert_true(strpos($envCmd, 'copy') === 0, 'check-configuration.php에서 Windows copy 명령어를 사용해야 합니다');
    // 슬래시가 백슬래시로 변환되었는지 확인
    assert_true(strpos($envCmd, '/') === false || strpos($envCmd, '\\') !== false, 'Windows에서 경로에 백슬래시가 포함되어야 합니다');
} else {
    assert_true(strpos($envCmd, 'cp') === 0, 'check-configuration.php에서 Linux cp 명령어를 사용해야 합니다');
}

// 결과 출력
echo "\n=== 테스트 결과 ===\n";
echo "통과: {$passed}, 실패: {$failed}\n";

if ($failed > 0) {
    echo "\n실패한 테스트:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

echo "\n모든 테스트 통과!\n";
exit(0);
