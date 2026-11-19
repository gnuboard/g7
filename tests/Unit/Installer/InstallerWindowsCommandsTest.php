<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 Windows 명령어 대응 테스트
 *
 * functions.php의 isWindows(), getEnvCopyCommand(), getPermissionFixCommand() 헬퍼 함수를 테스트합니다.
 *
 * 효과 요약(마커 아님 — 평문): installer_permission_command_covers_both_os_branches
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerWindowsCommandsTest extends TestCase
{
    /**
     * 프로젝트 루트 절대경로 (BASE_PATH 상수와 독립).
     *
     * 다른 인스톨러 테스트(#[RunClassInSeparateProcess] + temp BASE_PATH)가 메인
     * 프로세스로 BASE_PATH 를 누수시키면, 그 temp 경로 하위에는 public/install 이 없어
     * require 가 깨진다. 본 클래스는 __DIR__ 기준 절대경로로 인스톨러 파일을 로드해
     * 누수된 BASE_PATH 값과 무관하게 항상 실제 프로젝트 파일을 참조한다.
     *
     * @return string 이 테스트 파일에서 세 단계 위인 프로젝트 루트 경로
     */
    private function installerRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 테스트 전 인스톨러 설정 및 함수 파일을 로드합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // BASE_PATH 상수가 아직 정의되지 않은 경우만 정의
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2).'/..');
        }

        // REQUIRED_DIRECTORY_PERMISSIONS 상수들이 필요
        if (! defined('REQUIRED_DIRECTORY_PERMISSIONS')) {
            define('REQUIRED_DIRECTORY_PERMISSIONS', 0770);
        }
        if (! defined('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY')) {
            define('REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY', '770');
        }
        if (! defined('REQUIRED_DIRECTORIES')) {
            define('REQUIRED_DIRECTORIES', ['storage' => true]);
        }
        if (! defined('SUPPORTED_LANGUAGES')) {
            define('SUPPORTED_LANGUAGES', ['ko' => '한국어', 'en' => 'English']);
        }
        if (! defined('INSTALLER_BASE_URL')) {
            define('INSTALLER_BASE_URL', '/install');
        }

        // 함수 가용성 판정 SSoT(installerHasFunction) — 실제 진입점은 functions.php 보다 먼저 싣는다.
        // 싣지 않으면 hasWindowsReadOnlyAttribute() 의 attrib 폴백이 「판정기 미적재 = 확인 불가」로
        // 떨어져, 같은 프로세스에서 앞서 돈 다른 시험이 이 파일을 실었는지에 따라 결과가 갈린다.
        require_once $this->installerRoot().'/public/install/includes/execution-mode.php';

        // functions.php 로드 (한 번만) — 누수된 BASE_PATH 와 무관하게 프로젝트 루트 절대경로 사용
        require_once $this->installerRoot().'/public/install/includes/functions.php';
    }

    /**
     * isWindows() 함수가 현재 OS를 정확히 감지하는지 확인합니다.
     *
     * @scenario os_command_kind=env_copy
     *
     * @effects platform_detection_returns_boolean
     *
     * @return void 반환값 없음
     */
    public function test_is_windows_returns_bool(): void
    {
        $result = isWindows();
        $this->assertIsBool($result);

        // Windows 환경에서는 true여야 함
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->assertTrue($result);
        } else {
            $this->assertFalse($result);
        }
    }

    /**
     * getEnvCopyCommand()가 Windows에서 올바른 명령어를 반환하는지 확인합니다.
     *
     * @scenario os_command_kind=env_copy
     *
     * @effects env_copy_command_matches_platform, env_copy_command_honors_base_path
     *
     * @return void 반환값 없음
     */
    public function test_get_env_copy_command_with_base_path(): void
    {
        $command = getEnvCopyCommand('/var/www/g7');

        if (isWindows()) {
            $this->assertStringContainsString('copy', $command);
            $this->assertStringContainsString('\\', $command);
            $this->assertStringContainsString('.env.example', $command);
            $this->assertStringContainsString('.env', $command);
        } else {
            $this->assertStringContainsString('cp', $command);
            $this->assertStringContainsString('/', $command);
            $this->assertStringContainsString('.env.example', $command);
        }
    }

    /**
     * getEnvCopyCommand()가 빈 경로에서도 동작하는지 확인합니다.
     *
     * @scenario os_command_kind=env_copy
     *
     * @effects env_copy_command_matches_platform
     *
     * @return void 반환값 없음
     */
    public function test_get_env_copy_command_without_base_path(): void
    {
        $command = getEnvCopyCommand('');

        if (isWindows()) {
            $this->assertStringStartsWith('copy ', $command);
        } else {
            $this->assertStringStartsWith('cp ', $command);
        }

        $this->assertStringContainsString('.env.example', $command);
        $this->assertStringContainsString('.env', $command);
    }

    /**
     * getPermissionFixCommand()가 Windows에서 icacls 명령어를 반환하는지 확인합니다.
     *
     * 현재 시그니처: getPermissionFixCommand(string $pathList, string $mode = 'ownership')
     * (이전 (owner, path, mode) 3-arg 에서 리팩토링되어 owner 인자 제거됨)
     *
     * @scenario os_command_kind=permission_fix, permission_failure_cause=acl_only
     *
     * @effects permission_fix_command_ownership_mode
     *
     * @return void 반환값 없음
     */
    public function test_get_permission_fix_command_ownership_mode(): void
    {
        $command = getPermissionFixCommand('/var/www/storage', 'ownership');

        if (isWindows()) {
            $this->assertStringContainsString('icacls', $command);
            $this->assertStringContainsString('/grant', $command);
            $this->assertStringContainsString('Everyone:(OI)(CI)F', $command);
            $this->assertStringContainsString('/T', $command);
        } else {
            $this->assertStringContainsString('chmod -R 755 /var/www/storage', $command);
        }
    }

    /**
     * getPermissionFixCommand()가 windows_readonly 모드에서 attrib -R 을 반환하는지 확인합니다.
     *
     * ReadOnly 는 ACL 이 아니라 파일 속성이므로 icacls 로는 해제되지 않는다.
     * 이 모드는 icacls 대신 attrib -R 을 안내해야 한다 (재발 방지).
     *
     * @scenario permission_failure_cause=readonly_attribute
     *
     * @effects readonly_mode_returns_attrib_command, readonly_mode_does_not_return_icacls
     *
     * @return void 반환값 없음
     */
    public function test_get_permission_fix_command_windows_readonly_mode(): void
    {
        if (! isWindows()) {
            $this->markTestSkipped('Windows 전용 경로입니다.');
        }

        $command = getPermissionFixCommand('/var/www/storage', 'windows_readonly');

        $this->assertStringContainsString('attrib -R', $command);
        $this->assertStringContainsString('/D', $command);
        $this->assertStringContainsString('/S', $command);
        $this->assertStringNotContainsString('icacls', $command);
    }

    /**
     * ReadOnly 속성이 걸린 디렉토리를 hasWindowsReadOnlyAttribute()가 탐지하는지 확인합니다.
     *
     * 정상 디렉토리를 ReadOnly 로 오진하면 엉뚱한 명령을 안내하게 되므로 음성 케이스도 함께 검증한다.
     *
     * @scenario permission_failure_cause=readonly_attribute
     *
     * @effects readonly_directory_is_detected, normal_directory_is_not_misdiagnosed_as_readonly
     *
     * @return void 반환값 없음
     */
    public function test_has_windows_readonly_attribute_detects_readonly_directory(): void
    {
        if (! isWindows()) {
            $this->markTestSkipped('Windows 전용 경로입니다.');
        }

        $base = sys_get_temp_dir().'/g7_ro_'.uniqid();
        $readOnlyDir = $base.'/readonly';
        $normalDir = $base.'/normal';
        mkdir($readOnlyDir, 0777, true);
        mkdir($normalDir, 0777, true);

        @exec('attrib +R '.escapeshellarg($readOnlyDir).' /D');

        try {
            clearstatcache(true);
            $this->assertTrue(hasWindowsReadOnlyAttribute($readOnlyDir), 'ReadOnly 디렉토리를 탐지해야 합니다.');
            $this->assertFalse(hasWindowsReadOnlyAttribute($normalDir), '정상 디렉토리를 ReadOnly 로 오진하면 안 됩니다.');
        } finally {
            @exec('attrib -R '.escapeshellarg($readOnlyDir).' /D /S');
            @rmdir($readOnlyDir);
            @rmdir($normalDir);
            @rmdir($base);
        }
    }

    /**
     * getPermissionFixCommand()가 file 모드에서 올바른 명령어를 반환하는지 확인합니다.
     *
     * @scenario os_command_kind=permission_fix, permission_failure_cause=acl_only
     *
     * @effects permission_fix_command_file_mode
     *
     * @return void 반환값 없음
     */
    public function test_get_permission_fix_command_file_mode(): void
    {
        $command = getPermissionFixCommand('/var/www/.env', 'file');

        if (isWindows()) {
            $this->assertStringContainsString('icacls', $command);
            $this->assertStringContainsString('Everyone:F', $command);
            $this->assertStringNotContainsString('/T', $command);
        } else {
            $this->assertStringContainsString('chmod 644 /var/www/.env', $command);
        }
    }

    /**
     * getPermissionFixCommand()가 Windows에서 경로 구분자를 백슬래시로 변환하는지 확인합니다.
     *
     * @scenario os_command_kind=permission_fix, permission_failure_cause=acl_only
     *
     * @effects permission_fix_command_uses_windows_path_separator
     *
     * @return void 반환값 없음
     */
    public function test_get_permission_fix_command_windows_path_separator(): void
    {
        if (! isWindows()) {
            $this->markTestSkipped('Windows 전용 테스트');
        }

        $command = getPermissionFixCommand('/var/www/storage', 'ownership');
        $this->assertStringContainsString('\\var\\www\\storage', $command);
        $this->assertStringNotContainsString('/var/', $command);
    }

    /**
     * getEnvCopyCommand()가 Windows에서 경로 구분자를 백슬래시로 변환하는지 확인합니다.
     *
     * @scenario os_command_kind=env_copy
     *
     * @effects env_copy_command_uses_windows_path_separator
     *
     * @return void 반환값 없음
     */
    public function test_get_env_copy_command_windows_path_separator(): void
    {
        if (! isWindows()) {
            $this->markTestSkipped('Windows 전용 테스트');
        }

        $command = getEnvCopyCommand('C:/Users/test/htdocs/g7');
        $this->assertStringContainsString('C:\\Users\\test\\htdocs\\g7', $command);
        $this->assertStringNotContainsString('C:/Users', $command);
    }

    /**
     * 다국어 파일에 permission_windows_hint 키가 존재하는지 확인합니다.
     *
     * @scenario os_command_kind=translation_keys
     *
     * @effects permission_translation_keys_exist
     *
     * @return void 반환값 없음
     */
    public function test_translation_keys_exist(): void
    {
        $koTranslations = require $this->installerRoot().'/public/install/lang/ko.php';
        $enTranslations = require $this->installerRoot().'/public/install/lang/en.php';

        $this->assertArrayHasKey('permission_windows_hint', $koTranslations, 'ko.php에 permission_windows_hint 키가 없습니다');
        $this->assertArrayHasKey('permission_windows_hint', $enTranslations, 'en.php에 permission_windows_hint 키가 없습니다');
        $this->assertNotEmpty($koTranslations['permission_windows_hint']);
        $this->assertNotEmpty($enTranslations['permission_windows_hint']);
    }

    /**
     * 다국어 파일에 directory_create_guide 키가 존재하는지 확인합니다.
     *
     * @scenario os_command_kind=translation_keys
     *
     * @effects directory_create_guide_translation_keys_exist
     *
     * @return void 반환값 없음
     */
    public function test_directory_create_guide_translation_keys_exist(): void
    {
        $koTranslations = require $this->installerRoot().'/public/install/lang/ko.php';
        $enTranslations = require $this->installerRoot().'/public/install/lang/en.php';

        $this->assertArrayHasKey('directory_create_guide', $koTranslations, 'ko.php에 directory_create_guide 키가 없습니다');
        $this->assertArrayHasKey('directory_create_guide', $enTranslations, 'en.php에 directory_create_guide 키가 없습니다');
        $this->assertNotEmpty($koTranslations['directory_create_guide']);
        $this->assertNotEmpty($enTranslations['directory_create_guide']);
    }

    /**
     * 두 OS 분기를 **같은 프로세스에서** 태운다.
     *
     * 종전에는 `if (isWindows())` 로 갈라져 그 머신의 분기 하나만 평가됐다 — Windows 에서
     * 돌리면 `chmod` 계열이, Linux 에서 돌리면 `icacls` 계열이 한 번도 실행되지 않았고,
     * Windows 전용 두 테스트는 Linux 에서 통째로 skip 이라 「skip 0」이라는 수치도 측정 OS
     * 한정이었다. 판정을 OS 인자로 받는 함수로 분리해 양쪽을 고정한다.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: array<int, string>, 4: array<int, string>}> 경로, 모드, Windows 여부, 있어야 할 조각, 없어야 할 조각을 담은 사례 표
     */
    public static function permissionCommandCases(): array
    {
        return [
            'windows ownership' => ['/var/www/storage', 'ownership', true,
                ['icacls', '(OI)(CI)F', '/T', '\\var\\www'], ['chmod', '/var/www/storage']],
            'windows file' => ['/var/www/.env', 'file', true,
                ['icacls', 'Everyone:F'], ['/T', 'chmod']],
            'posix ownership' => ['/var/www/storage', 'ownership', false,
                ['chmod -R 755 /var/www/storage'], ['icacls']],
            'posix file' => ['/var/www/.env', 'file', false,
                ['chmod 644 /var/www/.env'], ['icacls', '-R']],
        ];
    }

    /**
     * OS 를 인자로 받는 권한 수정 명령 판정이 Windows 와 POSIX 두 분기 모두에서 있어야 할 조각을 담고 없어야 할 조각을 뺐는지 한 프로세스에서 확인한다.
     *
     * @scenario os_command_kind=permission_fix, permission_failure_cause=acl_only
     *
     * @effects installer_permission_command_covers_both_os_branches
     *
     * @param  string  $path  명령에 실을 대상 경로
     * @param  string  $mode  권한 수정 모드(ownership 또는 file)
     * @param  bool  $isWindows  Windows 분기로 판정할지 여부
     * @param  array<int, string>  $expected  명령에 들어 있어야 할 조각 목록
     * @param  array<int, string>  $forbidden  명령에 들어 있으면 안 되는 조각 목록
     * @return void 반환값 없음
     */
    #[DataProvider('permissionCommandCases')]
    public function test_permission_fix_command_covers_both_os_branches(
        string $path,
        string $mode,
        bool $isWindows,
        array $expected,
        array $forbidden,
    ): void {
        $command = installerPermissionFixCommandFor($path, $mode, $isWindows);

        foreach ($expected as $needle) {
            $this->assertStringContainsString($needle, $command, $mode.'/'.($isWindows ? 'win' : 'posix'));
        }

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $command, $mode.'/'.($isWindows ? 'win' : 'posix'));
        }
    }

    /**
     * 공개 진입점이 그 판정을 실제로 지나는가 — 지나지 않으면 위 표가 사문이 된다.
     *
     * @scenario os_command_kind=permission_fix
     *
     * @effects installer_permission_command_covers_both_os_branches
     *
     * @return void 반환값 없음
     */
    public function test_public_entry_point_delegates_to_the_os_aware_judgment(): void
    {
        $this->assertSame(
            installerPermissionFixCommandFor('/var/www/storage', 'ownership', isWindows()),
            getPermissionFixCommand('/var/www/storage', 'ownership'),
        );
    }
}
