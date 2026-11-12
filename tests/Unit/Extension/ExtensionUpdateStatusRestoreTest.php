<?php

namespace Tests\Unit\Extension;

use App\Extension\Support\StatusRestore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 확장 업데이트 실패 시의 상태 복원 쓰기 — 한 번 실패하면 연결을 다시 맺고 한 번 더 시도한다 (7.1.6).
 *
 * 업데이트 실패 원인이 DB 연결 고갈이면 「업데이트 중 → 이전 상태」 쓰기도 같은 이유로 실패했다. 그 예외가 원래
 * 실패를 가리고 확장이 「업데이트 중」에 남았다.
 *
 * @scenario-manifest tests/scenarios/extension-update-failure-cleanup.yaml
 */
class ExtensionUpdateStatusRestoreTest extends TestCase
{
    /**
     * 첫 쓰기가 성공하면 다시 연결하지 않는다.
     *
     * @scenario case=restore_first_try
     *
     * @effects status_restore_succeeds_without_reconnect
     *
     * @return void 반환값 없음
     */
    public function test_first_successful_write_does_not_reconnect(): void
    {
        DB::partialMock()->shouldReceive('reconnect')->never();
        $calls = 0;

        $restored = StatusRestore::run(function () use (&$calls): void {
            $calls++;
        }, 'module', 'probe-module');

        $this->assertTrue($restored);
        $this->assertSame(1, $calls);
    }

    /**
     * 첫 쓰기가 실패하면 연결을 다시 맺고 한 번 더 써서 상태를 되돌린다.
     *
     * @scenario case=restore_after_reconnect
     *
     * @effects status_restore_retries_once_after_reconnect
     *
     * @return void 반환값 없음
     */
    public function test_failed_write_is_retried_once_after_reconnect(): void
    {
        DB::partialMock()->shouldReceive('reconnect')->once();
        $status = 'updating';
        $calls = 0;

        $restored = StatusRestore::run(function () use (&$calls, &$status): void {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('SQLSTATE[08004] [1040] Too many connections');
            }
            $status = 'active';
        }, 'plugin', 'probe-plugin');

        $this->assertTrue($restored);
        $this->assertSame(2, $calls);
        $this->assertSame('active', $status);
    }

    /**
     * 두 번 모두 실패하면 예외를 던지지 않고 기록만 남긴다 — 원래 업데이트 실패가 가려지지 않게.
     *
     * @scenario case=restore_fails_twice
     *
     * @effects status_restore_failure_is_logged_not_thrown
     *
     * @return void 반환값 없음
     */
    public function test_two_failures_are_logged_and_not_thrown(): void
    {
        DB::partialMock()->shouldReceive('reconnect')->once();
        Log::partialMock()->shouldReceive('error')->once()->withArgs(function (string $message, array $context): bool {
            return str_contains($message, '10분') && $context['type'] === 'template' && $context['identifier'] === 'probe-template'
                && str_contains($context['error'], 'Too many connections');
        });
        $calls = 0;

        $restored = StatusRestore::run(function () use (&$calls): void {
            $calls++;
            throw new \RuntimeException('Too many connections');
        }, 'template', 'probe-template');

        $this->assertFalse($restored);
        $this->assertSame(2, $calls);
    }

    /**
     * 세 확장 관리자의 업데이트 실패 갈래가 모두 이 도우미로 상태를 되돌린다.
     *
     * @scenario case=managers_use_helper
     *
     * @effects all_extension_managers_restore_status_safely
     *
     * @return void 반환값 없음
     */
    public function test_all_three_managers_use_the_helper_in_the_failure_branch(): void
    {
        foreach (['Module' => 'module', 'Plugin' => 'plugin', 'Template' => 'template'] as $class => $type) {
            $source = str_replace("\r\n", "\n", (string) file_get_contents(app_path("Extension/{$class}Manager.php")));
            $repository = lcfirst($class).'Repository';

            // 실패 갈래: 파일을 되돌렸으면 버전 칸도 함께 되돌리는 쓰기 — 도우미로 감싼다
            $this->assertSame(1, preg_match(
                '/StatusRestore::run\(fn \(\) => \$this->'.$repository.'->updateByIdentifier\(\$identifier, \[\s+\'status\' => \$previousStatus,\s+\'updated_at\' => now\(\),\s+\.\.\.\(\$filesRestored/',
                $source,
            ), $class);
            $this->assertStringContainsString("]), '{$type}', \$identifier);", $source, $class);

            // 감싸지 않은 같은 쓰기가 남아 있지 않다
            $this->assertSame(0, preg_match(
                '/(?<!fn \(\) => )\$this->'.$repository.'->updateByIdentifier\(\$identifier, \[\s+\'status\' => \$previousStatus,\s+\'updated_at\' => now\(\),\s+\.\.\.\(\$filesRestored/',
                $source,
            ), $class);
        }
    }
}
