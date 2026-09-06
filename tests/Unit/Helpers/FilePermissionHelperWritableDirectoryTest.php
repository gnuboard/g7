<?php

namespace Tests\Unit\Helpers;

use App\Extension\Helpers\FilePermissionHelper;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `FilePermissionHelper::ensureWritableDirectory()` 단위 테스트.
 *
 * 이 프리미티브의 존재 이유는 "확보 실패가 다시 500 을 내지 않는 것" 이다. 제3자 라이브러리에
 * 쓰기 경로를 지정하는 목적 자체가 vendor 쓰기 실패로 인한 500 을 막는 것인데, 확보 지점이
 * PHP 경고를 내면 Laravel `HandleExceptions` 가 이를 `ErrorException` 으로 승격시켜 같은 500 이
 * 다른 줄에서 그대로 난다 (공개 #125 의 2차 결함).
 *
 * 그래서 실패 케이스는 반환값만 보지 않고 **경고가 나지 않았다는 것까지** 단언한다 — 경고를
 * 예외로 바꾸는 핸들러를 씌운 채로 호출해, 승격이 일어나면 테스트가 실패하도록 만든다.
 */
class FilePermissionHelperWritableDirectoryTest extends TestCase
{
    /** 테스트가 만든 경로 (tearDown 정리 대상) */
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/writable-dir-'.getmypid());
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * PHP 경고를 `ErrorException` 으로 승격시키는 핸들러 아래에서 콜백을 실행합니다.
     *
     * `Illuminate\Foundation\Bootstrap\HandleExceptions::handleError()` 와 **동형**이어야 한다 —
     * 그 핸들러는 `error_reporting() & $level` 을 확인하므로 `@` 로 억제된 진단은 승격시키지
     * 않는다. 이 검사를 빠뜨리면 억제를 존중하는 올바른 코드까지 실패로 보고해, 실제 운영에서는
     * 나지 않는 500 을 있다고 말하게 된다.
     *
     * 따라서 이 헬퍼가 잡아내는 것은 정확히 하나다 — **억제되지 않은 경고가 새어 나가는가.**
     * `File::makeDirectory(..., force: true)` 를 `ensureDirectoryExists()` 로 되돌리면 여기서 걸린다.
     *
     * @param  \Closure  $callback  실행할 콜백
     * @return mixed 콜백 반환값
     */
    private function withWarningsAsExceptions(\Closure $callback): mixed
    {
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
            if (error_reporting() & $level) {
                throw new \ErrorException($message, 0, $level, $file, $line);
            }

            return true;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * 없는 디렉토리를 만들고 쓰기 가능으로 판정합니다.
     */
    public function test_creates_missing_directory_and_reports_writable(): void
    {
        $target = $this->root.'/created/deeply/nested';

        $this->assertFalse(is_dir($target));

        $result = FilePermissionHelper::ensureWritableDirectory($target, 0775, $failure);

        $this->assertTrue($result);
        $this->assertNull($failure);
        $this->assertDirectoryExists($target);
        $this->assertTrue(is_writable($target));
    }

    /**
     * 이미 있는 쓰기 가능 디렉토리는 그대로 통과합니다 (재생성하지 않음).
     */
    public function test_returns_true_for_existing_writable_directory(): void
    {
        $target = $this->root.'/existing';
        File::ensureDirectoryExists($target);
        File::put($target.'/keep.txt', 'keep');

        $this->assertTrue(FilePermissionHelper::ensureWritableDirectory($target, 0775, $failure));
        $this->assertNull($failure);

        // 내용이 보존됐다 = 지우고 다시 만들지 않았다.
        $this->assertFileExists($target.'/keep.txt');
    }

    /**
     * 같은 이름의 파일이 자리를 차지하면 경고 없이 실패 사유를 돌려줍니다.
     */
    public function test_reports_occupied_by_file_without_raising_warning(): void
    {
        $target = $this->root.'/occupied';
        File::put($target, 'not a directory');

        $failure = null;
        $result = $this->withWarningsAsExceptions(
            function () use ($target, &$failure) {
                return FilePermissionHelper::ensureWritableDirectory($target, 0775, $failure);
            }
        );

        $this->assertFalse($result);
        $this->assertSame('occupied_by_file', $failure['reason']);
        $this->assertSame($target, $failure['path']);
    }

    /**
     * 경로 중간이 파일이라 생성이 불가능해도 경고 없이 실패 사유를 돌려줍니다.
     *
     * 상위(`$this->root`)는 쓰기 가능하므로 `ancestor_not_writable` 로 걸러지지 않고 실제
     * `mkdir` 까지 가서 실패하는 경로다 — 억제되지 않은 `mkdir` 이었다면 여기서 경고가 난다.
     */
    public function test_reports_create_failed_when_a_path_segment_is_a_file(): void
    {
        $blocker = $this->root.'/blocker';
        File::put($blocker, 'file in the middle of the path');

        $target = $blocker.'/sub/cache';

        $failure = null;
        $result = $this->withWarningsAsExceptions(
            function () use ($target, &$failure) {
                return FilePermissionHelper::ensureWritableDirectory($target, 0775, $failure);
            }
        );

        $this->assertFalse($result);
        $this->assertSame('create_failed', $failure['reason']);
        $this->assertSame($target, $failure['path']);
        $this->assertDirectoryDoesNotExist($target);
    }

    /**
     * 실재하는 최근접 상위를 찾아 올라갑니다.
     */
    public function test_nearest_existing_ancestor_walks_up_to_first_existing_directory(): void
    {
        $this->assertSame(
            rtrim($this->root, '/\\'),
            rtrim((string) FilePermissionHelper::nearestExistingAncestor($this->root.'/a/b/c'), '/\\')
        );

        $existing = $this->root.'/present';
        File::ensureDirectoryExists($existing);

        $this->assertSame(
            rtrim($existing, '/\\'),
            rtrim((string) FilePermissionHelper::nearestExistingAncestor($existing.'/child'), '/\\')
        );
    }

    /**
     * 정합화는 대상이 없어도 예외·경고를 내지 않습니다.
     *
     * `hardenDirectory()` 는 `@` 억제 chmod 와 소유권 상속만 수행하므로, 경합으로 대상이
     * 사라진 상황에서도 호출자에게 실패를 던지지 않아야 한다.
     */
    public function test_harden_directory_is_silent_when_target_is_absent(): void
    {
        $missing = $this->root.'/vanished';

        $this->withWarningsAsExceptions(function () use ($missing): void {
            FilePermissionHelper::hardenDirectory($missing, 0775);
        });

        $this->assertDirectoryDoesNotExist($missing);
    }
}
