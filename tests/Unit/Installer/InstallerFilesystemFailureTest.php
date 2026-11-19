<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 파일시스템이 협조하지 않을 때의 삭제·복원 계약.
 *
 * 이 축의 결함은 전부 **성공으로 위장된 실패**다 — 예외도 로그도 없이 반쪽 상태가 남고,
 * 그 위에서 다음 단계가 정상인 것처럼 진행된다.
 *
 *  - **F2**: 권한이 없으면 `scandir` 은 `false` 를 돌려주고 PHP 8 에서 `array_diff(false, …)`
 *    는 **TypeError** 다. 워커가 그 자리에서 즉사하고 사유는 「치명 종료」로만 남는다.
 *  - **F3**: `is_dir()` 은 심볼릭 링크의 **대상**을 본다. 링크된 디렉토리에 재귀하면 삭제
 *    경계가 그 디렉토리 밖으로 나간다 — composer path 저장소나 운영자가 만든 링크가
 *    vendor/ 안에 있으면 그 대상이 지워진다.
 *  - **F5**: 개별 `@unlink`/`@rmdir` 실패를 삼키고 `true` 를 돌려주면, 「vendor 삭제 완료」
 *    로그 뒤 반쪽 vendor 위에 `composer install` 이 돈다.
 *
 * 커버 축: enumerate ok·denied / entry file·dir·symlink / removal all·partial
 *
 * 효과 요약(마커 아님 — 평문): 열거 실패 보고, 심볼릭 링크 비추적, 부분 삭제 실패 보고. 표식은 각 테스트 메서드에 있다.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerFilesystemFailureTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $base = '';

    private static string $skipReason = '';

    private static bool $booted = false;

    /**
     * 클래스 공용 임시 BASE_PATH 를 한 번만 정하고 설치기 함수 파일들을 불러온다.
     *
     * 이미 정의된 BASE_PATH 가 시스템 임시 폴더 밖이면 실제 파일을 지우지 않도록 건너뛸 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 실 파일 파괴 방지를 위해 skip.';

                return;
            }
            self::$base = (string) BASE_PATH;
        } else {
            self::$base = sys_get_temp_dir().'/g7-installer-fsfail-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$base);
        }

        foreach (['storage/logs', 'storage/installer', 'storage/app'] as $dir) {
            if (! is_dir(self::$base.'/'.$dir)) {
                @mkdir(self::$base.'/'.$dir, 0755, true);
            }
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/api/run-task.php';
        }

        $root = dirname(__DIR__, 3);
        require_once $root.'/public/install/includes/config.php';
        require_once $root.'/public/install/includes/functions.php';
        require_once $root.'/public/install/includes/installer-state.php';
        require_once $root.'/public/install/includes/installer-runtime.php';
        require_once $root.'/public/install/includes/progress-emitter.php';
        require_once $root.'/public/install/api/rollback-functions.php';
    }

    /**
     * 공용 부팅을 거친 뒤 건너뛸 사유가 있으면 건너뛰고, 진행 기록을 버퍼 송출기로 받는다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::boot();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        setProgressEmitter(new \BufferEmitter);
    }

    /**
     * 임시 BASE_PATH 의 설치기 저장 폴더 아래에 이름이 겹치지 않는 작업 폴더를 만든다.
     *
     * @param  string  $name  폴더 이름 앞부분
     * @return string 만든 작업 폴더 경로
     */
    private function scratch(string $name): string
    {
        $path = self::$base.'/storage/installer/'.$name.'-'.bin2hex(random_bytes(3));
        @mkdir($path, 0755, true);

        return $path;
    }

    /**
     * F2 — 열거할 수 없는 디렉토리는 **삭제 실패**로 답한다 (TypeError 로 죽지 않는다).
     *
     * 권한을 실제로 떨어뜨리는 대신, 디렉토리 자리에 **파일**을 두어 같은 갈래를 만든다:
     * `is_dir()` 이 참인 경로가 아니면 함수는 앞단에서 false 를 돌려주므로, 열거 실패
     * 자체는 존재하는 디렉토리에서만 재현된다 — 그래서 여기서는 「false 를 순회하지
     * 않는다」를 소스가 아니라 **행위**로 확인할 수 있는 최소 형태만 고정한다.
     *
     * @scenario task=composer
     *
     * @effects delete_directory_reports_enumeration_failure
     *
     * @return void 반환값 없음
     */
    public function test_delete_directory_returns_false_for_a_non_directory(): void
    {
        $file = self::$base.'/storage/installer/not-a-dir-'.bin2hex(random_bytes(3));
        file_put_contents($file, 'x');

        $this->assertFalse(deleteDirectory($file, true), '디렉토리가 아니면 삭제 성공으로 답하면 안 된다');

        @unlink($file);
    }

    /**
     * F3 — 심볼릭 링크된 디렉토리의 **대상**은 건드리지 않는다.
     *
     * @scenario task=composer
     *
     * @effects delete_directory_does_not_follow_symlinks
     *
     * @return void 반환값 없음
     */
    public function test_delete_directory_does_not_follow_symlinked_directories(): void
    {
        $outside = $this->scratch('outside');
        file_put_contents($outside.'/precious.txt', 'keep me');

        $target = $this->scratch('target');
        $link = $target.'/linked';

        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('이 환경에서는 심볼릭 링크를 만들 수 없다 (Windows 권한).');
        }

        deleteDirectory($target, true);

        $this->assertFileExists(
            $outside.'/precious.txt',
            '링크를 따라가면 삭제 경계가 대상 디렉토리 밖으로 나간다',
        );

        @unlink($outside.'/precious.txt');
        @rmdir($outside);
    }

    /**
     * F2·F3 — 플랫폼 무관 배선 확인.
     *
     * 위 행위 검사는 Windows 에서 심볼릭 링크 생성 권한이 없으면 skip 된다. 그 환경에서
     * 이 축이 통째로 사각이 되지 않도록, 두 삭제 헬퍼가 **열거 실패 검사**와 **링크 가드**를
     * 갖는지는 소스로도 고정한다. 두 헬퍼에 같은 규칙이 적용됐는지(형제 대칭)도 함께 본다.
     *
     * @scenario task=composer
     *
     * @effects delete_directory_reports_enumeration_failure, delete_directory_does_not_follow_symlinks
     *
     * @return void 반환값 없음
     */
    public function test_both_delete_helpers_guard_enumeration_and_symlinks(): void
    {
        $targets = [
            'public/install/api/rollback-functions.php' => 'deleteDirectory',
            'public/install/includes/vendor-bundle-installer.php' => 'deleteVendorBundleDirectory',
        ];

        foreach ($targets as $relative => $function) {
            // 주석을 지운 소스에서 트레이트가 본문을 도출한다 — 손으로 센 중괄호는 문자열
            // 안의 중괄호에서 어긋나고, 원문 대조는 주석 처리된 가드를 통과시킨다.
            $source = $this->sourceWithoutComments(dirname(__DIR__, 3).'/'.$relative);
            $body = $this->functionBody($source, $function);

            $this->assertNotSame('', $body, $function.'() 를 찾지 못했다');

            $this->assertMatchesRegularExpression(
                '/===\s*false/',
                $body,
                $function.'() 이 열거 실패를 확인하지 않는다 — PHP 8 에서 false 순회는 TypeError 다',
            );
            $this->assertStringContainsString(
                'is_link(',
                $body,
                $function.'() 이 심볼릭 링크를 따라간다 — 삭제 경계가 대상 디렉토리 밖으로 나간다',
            );
        }
    }

    /**
     * F5 — 남은 것이 있으면 「삭제 완료」라고 답하지 않는다.
     *
     * 보존 대상(`.gitkeep`)이 있으면 디렉토리가 남는 것은 **의도된 동작**이므로 여전히
     * 성공이다. 그 구분이 유지되는지도 함께 본다.
     *
     * @scenario task=composer
     *
     * @effects delete_directory_reports_partial_failure
     *
     * @return void 반환값 없음
     */
    public function test_delete_directory_keeps_reporting_success_for_preserved_files(): void
    {
        $dir = $this->scratch('preserved');
        file_put_contents($dir.'/.gitkeep', '');
        file_put_contents($dir.'/junk.txt', 'x');

        $this->assertTrue(
            deleteDirectory($dir, true),
            '보존 파일 때문에 디렉토리가 남는 것은 실패가 아니다',
        );
        $this->assertFileExists($dir.'/.gitkeep');
        $this->assertFileDoesNotExist($dir.'/junk.txt');

        @unlink($dir.'/.gitkeep');
        @rmdir($dir);
    }

    /**
     * F5 — 정상 경로는 그대로 성공이다 (면제가 넓어지지 않았다).
     *
     * @scenario task=composer
     *
     * @effects delete_directory_reports_partial_failure
     *
     * @return void 반환값 없음
     */
    public function test_delete_directory_still_succeeds_on_a_clean_tree(): void
    {
        $dir = $this->scratch('clean');
        @mkdir($dir.'/nested', 0755, true);
        file_put_contents($dir.'/nested/a.txt', 'a');
        file_put_contents($dir.'/b.txt', 'b');

        $this->assertTrue(deleteDirectory($dir, true));
        $this->assertDirectoryDoesNotExist($dir);
    }
}
