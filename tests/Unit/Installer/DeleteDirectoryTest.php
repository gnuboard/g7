<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * deleteDirectory() 함수의 보호 파일 보존 테스트
 *
 * .gitkeep, .gitignore 등 Git 추적 파일이 삭제되지 않는지 검증합니다.
 */
class DeleteDirectoryTest extends TestCase
{
    /**
     * 테스트용 임시 디렉토리 경로
     */
    private string $tempDir;

    /**
     * 테스트 전 인스톨러 함수 로드 및 임시 디렉토리 생성
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 상수 정의 (한 번만)
        if (! defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2).'/..');
        }
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

        // 인스톨러 함수 로드 (pure PHPUnit TestCase 라서 Laravel base_path() 사용 불가 — BASE_PATH 상수 사용)
        require_once BASE_PATH . '/public/install/includes/functions.php';
        require_once BASE_PATH . '/public/install/api/rollback-functions.php';

        // 임시 디렉토리 생성
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'g7_test_delete_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    /**
     * 테스트 후 임시 디렉토리 정리
     */
    protected function tearDown(): void
    {
        $this->cleanupTempDir($this->tempDir);
        parent::tearDown();
    }

    /**
     * deleteDirectory()가 .gitkeep 파일을 보존하는지 확인합니다.
     */
    public function test_preserves_gitkeep_file(): void
    {
        // Given: 디렉토리에 .gitkeep과 일반 파일이 있음
        file_put_contents($this->tempDir . '/.gitkeep', '');
        file_put_contents($this->tempDir . '/some_file.txt', 'test');
        mkdir($this->tempDir . '/subdir', 0755);
        file_put_contents($this->tempDir . '/subdir/nested.txt', 'test');

        // When: deleteDirectory 호출 (기본: removeDir=false)
        $result = deleteDirectory($this->tempDir);

        // Then: .gitkeep은 보존, 나머지는 삭제, 디렉토리 자체는 유지
        $this->assertTrue($result);
        $this->assertDirectoryExists($this->tempDir);
        $this->assertFileExists($this->tempDir . '/.gitkeep');
        $this->assertFileDoesNotExist($this->tempDir . '/some_file.txt');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/subdir');
    }

    /**
     * deleteDirectory()가 .gitignore 파일을 보존하는지 확인합니다.
     */
    public function test_preserves_gitignore_file(): void
    {
        // Given: 디렉토리에 .gitignore와 일반 파일이 있음
        file_put_contents($this->tempDir . '/.gitignore', '*');
        file_put_contents($this->tempDir . '/data.log', 'log');

        // When
        $result = deleteDirectory($this->tempDir);

        // Then
        $this->assertTrue($result);
        $this->assertDirectoryExists($this->tempDir);
        $this->assertFileExists($this->tempDir . '/.gitignore');
        $this->assertFileDoesNotExist($this->tempDir . '/data.log');
    }

    /**
     * deleteDirectory()가 보호 파일이 없으면 디렉토리를 유지하되 비워두는지 확인합니다.
     */
    public function test_keeps_directory_when_no_preserve_files_and_remove_dir_false(): void
    {
        // Given: 보호 파일 없이 일반 파일만 있음
        file_put_contents($this->tempDir . '/file1.txt', 'test');
        file_put_contents($this->tempDir . '/file2.txt', 'test');

        // When: removeDir=false (기본값)
        $result = deleteDirectory($this->tempDir);

        // Then: 디렉토리 자체는 유지, 내용물은 삭제
        $this->assertTrue($result);
        $this->assertDirectoryExists($this->tempDir);
        $this->assertFileDoesNotExist($this->tempDir . '/file1.txt');
        $this->assertFileDoesNotExist($this->tempDir . '/file2.txt');
    }

    /**
     * deleteDirectory()가 removeDir=true이고 보호 파일이 있으면 디렉토리를 유지하는지 확인합니다.
     */
    public function test_keeps_directory_when_preserve_files_exist_even_with_remove_dir(): void
    {
        // Given: .gitkeep이 있음
        file_put_contents($this->tempDir . '/.gitkeep', '');
        file_put_contents($this->tempDir . '/other.txt', 'test');

        // When: removeDir=true여도 보호 파일이 있으면 디렉토리 유지
        $result = deleteDirectory($this->tempDir, true);

        // Then
        $this->assertTrue($result);
        $this->assertDirectoryExists($this->tempDir);
        $this->assertFileExists($this->tempDir . '/.gitkeep');
        $this->assertFileDoesNotExist($this->tempDir . '/other.txt');
    }

    /**
     * deleteDirectory()가 removeDir=true이고 보호 파일이 없으면 디렉토리를 삭제하는지 확인합니다.
     */
    public function test_removes_directory_when_no_preserve_files_and_remove_dir_true(): void
    {
        // Given: 보호 파일 없음
        file_put_contents($this->tempDir . '/file.txt', 'test');

        // When
        $result = deleteDirectory($this->tempDir, true);

        // Then: 디렉토리도 삭제
        $this->assertDirectoryDoesNotExist($this->tempDir);
    }

    /**
     * deleteDirectory()가 중첩 디렉토리를 재귀적으로 처리하는지 확인합니다.
     */
    public function test_handles_nested_directories(): void
    {
        // Given: vendor 디렉토리 구조 시뮬레이션
        file_put_contents($this->tempDir . '/.gitkeep', '');
        mkdir($this->tempDir . '/laravel/framework/src', 0755, true);
        file_put_contents($this->tempDir . '/laravel/framework/src/Application.php', '<?php');
        file_put_contents($this->tempDir . '/autoload.php', '<?php');

        // When
        $result = deleteDirectory($this->tempDir);

        // Then: .gitkeep만 보존, 나머지 전부 삭제
        $this->assertTrue($result);
        $this->assertDirectoryExists($this->tempDir);
        $this->assertFileExists($this->tempDir . '/.gitkeep');
        $this->assertFileDoesNotExist($this->tempDir . '/autoload.php');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/laravel');
    }

    /**
     * deleteDirectory()가 빈 디렉토리에서도 정상 동작하는지 확인합니다.
     */
    public function test_handles_empty_directory(): void
    {
        // When
        $result = deleteDirectory($this->tempDir);

        // Then: 디렉토리 유지 (removeDir=false 기본값)
        $this->assertTrue($result);
        $this->assertDirectoryExists($this->tempDir);
    }

    /**
     * deleteDirectory()가 존재하지 않는 경로에서 false를 반환하는지 확인합니다.
     */
    public function test_returns_false_for_nonexistent_directory(): void
    {
        $result = deleteDirectory($this->tempDir . '/nonexistent');
        $this->assertFalse($result);
    }

    /**
     * 임시 디렉토리를 완전히 정리합니다 (테스트용).
     *
     * @param string $dir 정리할 디렉토리 경로
     */
    private function cleanupTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->cleanupTempDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
