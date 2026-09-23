<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;

/**
 * 설치기가 안내하는 읽기 전용 해제 명령이 폴더 **안쪽까지** 푸는지 실제로 실행해 확인합니다 (#599 validate).
 *
 * 종전 안내 `attrib -R "<storage>" /D /S` 는 경로를 파일 사양으로 읽어, 부모 폴더 아래에서 이름이
 * `storage` 인 항목만 푼다. 2026-09-23 Windows 실측: 폴더 자체의 R 은 풀렸지만 `storage\app` 과
 * `storage\app\logs\a.log` 는 읽기 전용으로 남았다. 안내대로 실행한 운영자는 같은 오류를 다시 본다.
 * 안쪽까지 풀려면 `attrib -R "<storage>\*" /S /D` 가 함께 있어야 한다.
 */
class InstallerReadonlyAttribClearsContentsTest extends TestCase
{
    /**
     * 설치기 함수 파일을 불러옵니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 3));
        }
        foreach (['REQUIRED_DIRECTORY_PERMISSIONS' => 0770, 'REQUIRED_DIRECTORY_PERMISSIONS_DISPLAY' => '770', 'REQUIRED_DIRECTORIES' => []] as $name => $value) {
            if (! defined($name)) {
                define($name, $value);
            }
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/functions.php';
    }

    /**
     * 안내 명령을 그대로 실행하면 폴더 안쪽 파일·하위 폴더의 읽기 전용까지 풀린다.
     *
     * @scenario permission_failure_cause=readonly_attribute
     *
     * @effects readonly_fix_command_clears_nested_contents
     *
     * @return void 반환값 없음
     */
    public function test_the_guided_command_clears_readonly_inside_the_folder(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('attrib 는 Windows 명령이다.');
        }

        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-attrib-'.uniqid();
        $storage = $root.DIRECTORY_SEPARATOR.'storage';
        $nested = $storage.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'logs';
        mkdir($nested, 0777, true);
        $file = $nested.DIRECTORY_SEPARATOR.'a.log';
        file_put_contents($file, 'x');

        try {
            exec('attrib +R '.escapeshellarg($file));
            exec('attrib +R '.escapeshellarg($storage.DIRECTORY_SEPARATOR.'app').' /D');
            exec('attrib +R '.escapeshellarg($storage).' /D');

            foreach (preg_split('/\r?\n/', getPermissionFixCommand($storage, 'windows_readonly')) as $line) {
                exec($line);
            }

            $this->assertFalse($this->isReadonly($file), '안쪽 파일이 읽기 전용으로 남았다');
            $this->assertFalse($this->isReadonly($storage.DIRECTORY_SEPARATOR.'app', true), '하위 폴더가 읽기 전용으로 남았다');
            $this->assertFalse($this->isReadonly($storage, true), '폴더 자체가 읽기 전용으로 남았다');
        } finally {
            exec('attrib -R '.escapeshellarg($root.DIRECTORY_SEPARATOR.'*').' /S /D');
            exec('rmdir /S /Q '.escapeshellarg($root));
        }
    }

    /**
     * attrib 조회 결과에 R 이 있는지 봅니다.
     *
     * @param  string  $path  대상 경로
     * @param  bool  $directory  폴더이면 true (/D 로 조회)
     * @return bool 읽기 전용이면 true
     */
    private function isReadonly(string $path, bool $directory = false): bool
    {
        exec('attrib '.escapeshellarg($path).($directory ? ' /D' : ''), $output);
        $flags = substr((string) ($output[0] ?? ''), 0, 12);

        return str_contains($flags, 'R');
    }
}
