<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\ScheduleRunState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 상태 파일 쓰기 실패가 드러나는지 (검증 R1 C-3).
 *
 * 쓰기에 실패하면 점검 시각과 이벤트별 실행 시각이 남지 않는다. 그러면 다음 호출의
 * 소급 창 하한이 늘 상한(기본 24시간)까지 열려 **창 안의 예약이 매 호출마다 전부 다시
 * 실행된다.** 쓰기 권한이 제한된 호스팅이 바로 이 기능의 대상 환경이라 실제로 겪을 수
 * 있는 상태이고, 증상은 오류가 아니라 「최소 간격마다 전체 정리 배치가 도는 부하」로만
 * 나타난다 — 운영자가 스스로 알아낼 단서가 없다.
 *
 * 그래서 실패를 오류 로그로 남기고 플래그로 보관해 화면까지 나른다. 그 통로 전체가
 * 지워져도 아무 시험도 울지 않던 자리라 여기서 잠근다.
 *
 * @scenario caller=external
 */
class ScheduleRunStateTest extends TestCase
{
    /** 케이스 전용 상태 파일 */
    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath = storage_path('framework/testing/schedule-run-state-'.bin2hex(random_bytes(4)).'.json');
    }

    protected function tearDown(): void
    {
        if (is_file($this->statePath)) {
            @unlink($this->statePath);
        }

        parent::tearDown();
    }

    /**
     * 정상 쓰기는 실패 플래그를 세우지 않는다 (반대 방향 고정).
     *
     * @effects schedule_state_persist_success_leaves_no_failure_flag
     */
    #[Test]
    public function test_successful_write_reports_no_failure(): void
    {
        $state = new ScheduleRunState($this->statePath);

        $state->recordInvocation(CarbonImmutable::parse('2026-08-31 09:00:00'));

        $this->assertFalse(
            $state->persistFailed(),
            '정상 쓰기인데 실패로 보고됐다 — 화면이 상시 경고를 띄우게 된다.',
        );
        $this->assertFileExists($this->statePath);
    }

    /**
     * 쓰기 실패는 플래그와 오류 로그로 드러난다.
     *
     * `File::put` 을 파사드에서 실패로 고정한다 — 권한으로 재현하면 Windows 에서
     * 성립하지 않아 그 환경의 CI 에서만 조용히 통과하는 가드가 된다.
     *
     * @effects schedule_state_persist_failure_is_reported
     */
    #[Test]
    public function test_write_failure_is_flagged_and_logged(): void
    {
        Log::spy();

        File::partialMock()->shouldReceive('put')->andReturn(false);

        $state = new ScheduleRunState($this->statePath);
        $state->recordInvocation(CarbonImmutable::parse('2026-08-31 09:00:00'));

        $this->assertTrue(
            $state->persistFailed(),
            '상태 파일을 쓰지 못했는데 실패로 보고되지 않았다 — 다음 호출의 소급 창이 '
            .'상한까지 열려 창 안의 예약이 매번 전부 다시 실행되는데 화면은 정상으로 보인다.',
        );

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, '상태')
                && ($context['path'] ?? null) === $this->statePath)
            ->once();
    }

    /**
     * 실패 뒤 성공하면 플래그가 내려간다 — 한 번 켜지고 고착되면 안 된다.
     *
     * @effects schedule_state_persist_flag_clears_after_recovery
     */
    #[Test]
    public function test_failure_flag_clears_once_a_write_succeeds(): void
    {
        $state = new ScheduleRunState($this->statePath);

        // 첫 쓰기는 실패, 두 번째는 성공 — 같은 인스턴스에서 순서대로 돌려준다.
        File::partialMock()->shouldReceive('put')->andReturn(false, true);

        $state->recordInvocation(CarbonImmutable::parse('2026-08-31 09:00:00'));
        $this->assertTrue($state->persistFailed(), '쓰기 실패가 보고되지 않았다.');

        $state->recordInvocation(CarbonImmutable::parse('2026-08-31 09:05:00'));

        $this->assertFalse(
            $state->persistFailed(),
            '쓰기가 복구됐는데 실패 플래그가 남았다 — 화면이 해소된 경고를 계속 띄운다.',
        );
    }
}
