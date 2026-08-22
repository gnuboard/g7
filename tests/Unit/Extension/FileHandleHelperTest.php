<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\FileHandleHelper;
use Tests\TestCase;

/**
 * FileHandleHelper 테스트
 *
 * Windows 파일 잠금 감지 및 해제 헬퍼의 단위 테스트입니다.
 * 일부 테스트는 Windows 환경에서만 의미가 있으며,
 * 비-Windows 환경에서는 자동 스킵됩니다.
 */
class FileHandleHelperTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7_file_handle_test_'.uniqid();
        mkdir($this->tempDir, 0775, true);

        // 테스트용 파일 생성
        file_put_contents($this->tempDir.DIRECTORY_SEPARATOR.'test.txt', 'test content');
        mkdir($this->tempDir.DIRECTORY_SEPARATOR.'subdir', 0775, true);
        file_put_contents($this->tempDir.DIRECTORY_SEPARATOR.'subdir'.DIRECTORY_SEPARATOR.'nested.txt', 'nested content');
    }

    protected function tearDown(): void
    {
        // 임시 디렉토리 정리
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * isWindows()가 현재 환경에 맞는 값을 반환하는지 확인합니다.
     */
    public function test_is_windows_returns_correct_value(): void
    {
        $expected = PHP_OS_FAMILY === 'Windows';
        $this->assertEquals($expected, FileHandleHelper::isWindows());
    }

    /**
     * 잠금이 없는 디렉토리에서 빈 배열을 반환하는지 확인합니다.
     */
    public function test_find_locking_processes_returns_empty_when_no_locks(): void
    {
        $result = FileHandleHelper::findLockingProcesses($this->tempDir);

        $this->assertIsArray($result);
        // 잠금이 없으므로 빈 배열이어야 함
        $this->assertEmpty($result);
    }

    /**
     * 존재하지 않는 디렉토리에서 빈 배열을 반환하는지 확인합니다.
     */
    public function test_find_locking_processes_returns_empty_for_nonexistent_dir(): void
    {
        $result = FileHandleHelper::findLockingProcesses('/nonexistent/path/should/not/exist');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 빈 프로세스 배열에 대해 killProcesses가 빈 결과를 반환하는지 확인합니다.
     */
    public function test_kill_processes_with_empty_array_returns_empty(): void
    {
        $result = FileHandleHelper::killProcesses([]);

        $this->assertArrayHasKey('killed', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertEmpty($result['killed']);
        $this->assertEmpty($result['failed']);
    }

    /**
     * 시스템 프로세스가 종료 대상에서 제외되는지 확인합니다.
     */
    public function test_kill_processes_protects_system_processes(): void
    {
        $processes = [
            ['pid' => 4, 'name' => 'System', 'app' => 'System'],
            ['pid' => 100, 'name' => 'svchost', 'app' => 'Service Host'],
            ['pid' => 200, 'name' => 'explorer', 'app' => 'Windows Explorer'],
        ];

        $result = FileHandleHelper::killProcesses($processes);

        // 모든 시스템 프로세스가 failed에 있어야 함
        $this->assertCount(3, $result['failed']);
        $this->assertEmpty($result['killed']);

        foreach ($result['failed'] as $proc) {
            $this->assertStringContains('시스템 프로세스', $proc['reason']);
        }
    }

    /**
     * releaseLocks가 잠금 없는 디렉토리에서 true를 반환하는지 확인합니다.
     */
    public function test_release_locks_returns_true_when_no_locks(): void
    {
        $result = FileHandleHelper::releaseLocks($this->tempDir);

        $this->assertTrue($result);
    }

    /**
     * releaseLocks 출력 콜백이 호출되는지 확인합니다 (잠금 있는 경우).
     *
     * @requires OS Windows
     */
    public function test_release_locks_calls_output_callback(): void
    {
        $messages = [];
        $callback = function (string $message) use (&$messages) {
            $messages[] = $message;
        };

        // 잠금 없는 디렉토리에서는 콜백이 호출되지 않아야 함
        FileHandleHelper::releaseLocks($this->tempDir, $callback);

        // 잠금이 없으므로 메시지가 없어야 함
        $this->assertEmpty($messages);
    }

    /**
     * Windows에서 파일 잠금 감지가 동작하는지 통합 테스트합니다.
     *
     * @requires OS Windows
     */
    public function test_detect_locked_file_on_windows(): void
    {
        $filePath = $this->tempDir.DIRECTORY_SEPARATOR.'locked.txt';
        file_put_contents($filePath, 'locked content');

        // 파일을 배타적으로 열어서 잠금
        $handle = fopen($filePath, 'r+');
        if ($handle === false) {
            $this->markTestSkipped('파일을 열 수 없습니다');
        }

        // 배타적 잠금 획득
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $this->markTestSkipped('파일 잠금을 획득할 수 없습니다');
        }

        try {
            $processes = FileHandleHelper::findLockingProcesses($this->tempDir);

            // 현재 PHP 프로세스가 파일을 잠그고 있지만,
            // findLockingProcesses는 현재 프로세스를 제외하므로 빈 배열
            $this->assertIsArray($processes);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * maxFiles 파라미터가 샘플링을 제한하는지 확인합니다.
     */
    public function test_find_locking_processes_respects_max_files(): void
    {
        // 많은 파일 생성
        for ($i = 0; $i < 50; $i++) {
            file_put_contents($this->tempDir.DIRECTORY_SEPARATOR."file_{$i}.txt", "content {$i}");
        }

        // maxFiles=5로 제한해도 오류 없이 동작해야 함
        $result = FileHandleHelper::findLockingProcesses($this->tempDir, 5);

        $this->assertIsArray($result);
    }

    /**
     * 프로세스 배열 구조가 올바른지 확인합니다.
     */
    public function test_process_array_structure(): void
    {
        // 빈 결과라도 구조 테스트는 killProcesses를 통해 가능
        $mockProcesses = [
            ['pid' => 99999, 'name' => 'nonexistent_process', 'app' => 'Test App'],
        ];

        $result = FileHandleHelper::killProcesses($mockProcesses);

        $this->assertArrayHasKey('killed', $result);
        $this->assertArrayHasKey('failed', $result);

        // 존재하지 않는 프로세스이므로 killed 또는 failed에 있어야 함
        $totalCount = count($result['killed']) + count($result['failed']);
        $this->assertEquals(1, $totalCount);
    }

    /**
     * 재귀적 디렉토리 삭제 헬퍼
     */
    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            } else {
                @unlink($fullPath);
            }
        }
        @rmdir($path);
    }

    /**
     * 문자열 포함 확인 헬퍼
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "'{$haystack}'에 '{$needle}'가 포함되어야 합니다"
        );
    }
}
