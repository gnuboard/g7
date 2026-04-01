<?php
/**
 * deleteDirectory() 보호 파일 보존 독립 테스트 스크립트
 *
 * vendor 없이 실행 가능한 단순 테스트 러너입니다.
 * 실행: php tests/Unit/Installer/run_delete_directory_test.php
 */

// 인스톨러 설정 로드
define('BASE_PATH', dirname(__DIR__, 3));
define('REQUIRED_DIRECTORY_PERMISSIONS', 0770);
define('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY', '770');
define('REQUIRED_DIRECTORIES', ['storage' => true]);
define('SUPPORTED_LANGUAGES', ['ko' => '한국어', 'en' => 'English']);
define('INSTALLER_BASE_URL', '/install');

require_once BASE_PATH . '/public/install/includes/functions.php';
require_once BASE_PATH . '/public/install/api/rollback-functions.php';

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

/**
 * 임시 디렉토리를 완전히 정리합니다 (테스트용).
 */
function cleanupTempDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            cleanupTempDir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

echo "=== deleteDirectory() 보호 파일 보존 테스트 ===\n\n";

// 1. .gitkeep 파일 보존 테스트
echo "[1] .gitkeep 파일 보존 테스트\n";
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'g7_test_' . uniqid();
mkdir($tempDir, 0755, true);
file_put_contents($tempDir . '/.gitkeep', '');
file_put_contents($tempDir . '/some_file.txt', 'test');
mkdir($tempDir . '/subdir', 0755);
file_put_contents($tempDir . '/subdir/nested.txt', 'test');

$result = deleteDirectory($tempDir);
assert_true($result === true, 'deleteDirectory()가 true를 반환해야 합니다');
assert_true(is_dir($tempDir), '디렉토리 자체는 유지되어야 합니다');
assert_true(file_exists($tempDir . '/.gitkeep'), '.gitkeep 파일이 보존되어야 합니다');
assert_true(!file_exists($tempDir . '/some_file.txt'), '일반 파일은 삭제되어야 합니다');
assert_true(!is_dir($tempDir . '/subdir'), '하위 디렉토리는 삭제되어야 합니다');
cleanupTempDir($tempDir);

// 2. .gitignore 파일 보존 테스트
echo "\n[2] .gitignore 파일 보존 테스트\n";
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'g7_test_' . uniqid();
mkdir($tempDir, 0755, true);
file_put_contents($tempDir . '/.gitignore', '*');
file_put_contents($tempDir . '/data.log', 'log');

$result = deleteDirectory($tempDir);
assert_true($result === true, 'deleteDirectory()가 true를 반환해야 합니다');
assert_true(is_dir($tempDir), '디렉토리 자체는 유지되어야 합니다');
assert_true(file_exists($tempDir . '/.gitignore'), '.gitignore 파일이 보존되어야 합니다');
assert_true(!file_exists($tempDir . '/data.log'), '일반 파일은 삭제되어야 합니다');
cleanupTempDir($tempDir);

// 3. removeDir=false (기본값) 시 디렉토리 유지 테스트
echo "\n[3] removeDir=false (기본값) 시 디렉토리 유지 테스트\n";
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'g7_test_' . uniqid();
mkdir($tempDir, 0755, true);
file_put_contents($tempDir . '/file1.txt', 'test');
file_put_contents($tempDir . '/file2.txt', 'test');

$result = deleteDirectory($tempDir);
assert_true($result === true, 'deleteDirectory()가 true를 반환해야 합니다');
assert_true(is_dir($tempDir), '보호 파일 없어도 디렉토리 자체는 유지되어야 합니다 (removeDir=false)');
assert_true(!file_exists($tempDir . '/file1.txt'), 'file1.txt는 삭제되어야 합니다');
assert_true(!file_exists($tempDir . '/file2.txt'), 'file2.txt는 삭제되어야 합니다');
cleanupTempDir($tempDir);

// 4. removeDir=true + 보호 파일 있음 → 디렉토리 유지
echo "\n[4] removeDir=true + 보호 파일 있음 → 디렉토리 유지 테스트\n";
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'g7_test_' . uniqid();
mkdir($tempDir, 0755, true);
file_put_contents($tempDir . '/.gitkeep', '');
file_put_contents($tempDir . '/other.txt', 'test');

$result = deleteDirectory($tempDir, true);
assert_true($result === true, 'deleteDirectory()가 true를 반환해야 합니다');
assert_true(is_dir($tempDir), '보호 파일이 있으면 removeDir=true여도 디렉토리 유지');
assert_true(file_exists($tempDir . '/.gitkeep'), '.gitkeep 파일이 보존되어야 합니다');
assert_true(!file_exists($tempDir . '/other.txt'), '일반 파일은 삭제되어야 합니다');
cleanupTempDir($tempDir);

// 5. removeDir=true + 보호 파일 없음 → 디렉토리 삭제
echo "\n[5] removeDir=true + 보호 파일 없음 → 디렉토리 삭제 테스트\n";
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'g7_test_' . uniqid();
mkdir($tempDir, 0755, true);
file_put_contents($tempDir . '/file.txt', 'test');

$result = deleteDirectory($tempDir, true);
assert_true(!is_dir($tempDir), '보호 파일 없고 removeDir=true면 디렉토리도 삭제');
// 이미 삭제됐으므로 정리 불필요

// 6. 중첩 디렉토리 (vendor 구조 시뮬레이션)
echo "\n[6] vendor 디렉토리 구조 시뮬레이션 테스트\n";
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'g7_test_' . uniqid();
mkdir($tempDir, 0755, true);
file_put_contents($tempDir . '/.gitkeep', '');
mkdir($tempDir . '/laravel/framework/src', 0755, true);
file_put_contents($tempDir . '/laravel/framework/src/Application.php', '<?php');
file_put_contents($tempDir . '/autoload.php', '<?php');

$result = deleteDirectory($tempDir);
assert_true($result === true, 'deleteDirectory()가 true를 반환해야 합니다');
assert_true(is_dir($tempDir), 'vendor 디렉토리 자체는 유지되어야 합니다');
assert_true(file_exists($tempDir . '/.gitkeep'), '.gitkeep 파일이 보존되어야 합니다');
assert_true(!file_exists($tempDir . '/autoload.php'), 'autoload.php는 삭제되어야 합니다');
assert_true(!is_dir($tempDir . '/laravel'), 'laravel 하위 디렉토리는 삭제되어야 합니다');
cleanupTempDir($tempDir);

// 7. 빈 디렉토리 테스트
echo "\n[7] 빈 디렉토리 테스트\n";
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'g7_test_' . uniqid();
mkdir($tempDir, 0755, true);

$result = deleteDirectory($tempDir);
assert_true($result === true, 'deleteDirectory()가 true를 반환해야 합니다');
assert_true(is_dir($tempDir), '빈 디렉토리도 유지되어야 합니다 (removeDir=false)');
cleanupTempDir($tempDir);

// 8. 존재하지 않는 경로 테스트
echo "\n[8] 존재하지 않는 경로 테스트\n";
$result = deleteDirectory(sys_get_temp_dir() . '/g7_nonexistent_' . uniqid());
assert_true($result === false, '존재하지 않는 경로에서 false를 반환해야 합니다');

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
