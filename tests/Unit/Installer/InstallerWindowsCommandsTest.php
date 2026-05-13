<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 Windows 명령어 대응 테스트
 *
 * functions.php의 isWindows(), getEnvCopyCommand(), getPermissionFixCommand() 헬퍼 함수를 테스트합니다.
 */
class InstallerWindowsCommandsTest extends TestCase
{
    /**
     * 테스트 전 인스톨러 설정 및 함수 파일을 로드합니다.
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

        // functions.php 로드 (한 번만) — base_path() 대신 BASE_PATH 상수 직접 사용
        require_once BASE_PATH . '/public/install/includes/functions.php';
    }

    /**
     * isWindows() 함수가 현재 OS를 정확히 감지하는지 확인합니다.
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
     * getPermissionFixCommand()가 file 모드에서 올바른 명령어를 반환하는지 확인합니다.
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
     */
    public function test_translation_keys_exist(): void
    {
        $koTranslations = require BASE_PATH . '/public/install/lang/ko.php';
        $enTranslations = require BASE_PATH . '/public/install/lang/en.php';

        $this->assertArrayHasKey('permission_windows_hint', $koTranslations, 'ko.php에 permission_windows_hint 키가 없습니다');
        $this->assertArrayHasKey('permission_windows_hint', $enTranslations, 'en.php에 permission_windows_hint 키가 없습니다');
        $this->assertNotEmpty($koTranslations['permission_windows_hint']);
        $this->assertNotEmpty($enTranslations['permission_windows_hint']);
    }

    /**
     * 다국어 파일에 directory_create_guide 키가 존재하는지 확인합니다.
     */
    public function test_directory_create_guide_translation_keys_exist(): void
    {
        $koTranslations = require BASE_PATH . '/public/install/lang/ko.php';
        $enTranslations = require BASE_PATH . '/public/install/lang/en.php';

        $this->assertArrayHasKey('directory_create_guide', $koTranslations, 'ko.php에 directory_create_guide 키가 없습니다');
        $this->assertArrayHasKey('directory_create_guide', $enTranslations, 'en.php에 directory_create_guide 키가 없습니다');
        $this->assertNotEmpty($koTranslations['directory_create_guide']);
        $this->assertNotEmpty($enTranslations['directory_create_guide']);
    }
}
