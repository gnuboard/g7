<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionClass;

/**
 * 관리자 구매확정의 활동 로그 전/후 표 (S2-D7 · #599 통합실측 세션2)
 *
 * 구매확정은 두 경로로 일어난다. 둘 다 같은 훅
 * (`sirsoft-ecommerce.order-option.after_confirm`)을 발화하고,
 * 같은 리스너(`OrderActivityLogListener::handleOrderOptionAfterConfirm`)가 받는다.
 *
 *   - 회원 셀프 확정 : `OrderService::confirmOption()`
 *   - 관리자 상태전이 : `OrderOptionService` 의 상태 변경 경로
 *
 * 리스너는 세 번째 인자로 받은 **변경 전 스냅샷**으로 `changes` 를 만든다.
 * 그 독블록이 이유를 명시한다:
 *
 *   > `changes` 를 싣는 이유: 활동 로그 화면은 여기에 실린 항목만 "변경 전 → 변경 후"
 *   > 표로 그린다. 줄만 남기면 이 사건으로 무엇이 바뀌었는지를 로그에서 읽을 수 없다.
 *
 * **그런데 관리자 경로만 스냅샷 없이 2인자로 발화했다.**
 * `ChangeDetector::detect($option, null)` 은 빈 결과를 내므로, 같은 사건인데
 * 관리자가 확정한 건만 전/후 표가 통째로 빈다.
 *
 * 실측(2026-09-15):
 *
 *   | 경로 | 옵션 | `order_option_confirm` 의 `changes` |
 *   | --- | --- | --- |
 *   | 회원 셀프(주문 107) | 231 · 232 | `option_status: delivered → confirmed` **있음** |
 *   | 관리자(주문 115)    | 245       | **빈 문자열** |
 *
 * 이 테스트는 **두 경로가 같은 인자수로 발화하는지**를 잠근다 — 한쪽만 고쳐지면 red 가 된다.
 */
class AdminConfirmPassesOptionSnapshotTest extends ModuleTestCase
{
    /**
     * 주어진 클래스 소스에서 `order-option.after_confirm` 발화 줄을 모두 찾는다.
     *
     * @param  class-string  $class  대상 서비스 클래스
     * @return array<int, string> 발화 줄(공백 정규화)
     */
    private function confirmHookCalls(string $class): array
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass($class))->getFileName()
        );

        preg_match_all(
            "/HookManager::doAction\(\s*'sirsoft-ecommerce\.order-option\.after_confirm'.*?\);/s",
            $source,
            $matches
        );

        return array_map(
            static fn (string $call): string => (string) preg_replace('/\s+/', ' ', $call),
            $matches[0] ?? []
        );
    }

    /**
     * 전제 확인 — 회원 셀프 확정은 스냅샷을 넘긴다.
     *
     * 이 경로가 기준이다. 여기서 스냅샷이 빠지면 관리자 경로만의 문제가 아니게 되므로
     * 함께 잠근다.
     *
     * @scenario confirm_path=user_self
     *
     * @effects user_confirm_carries_option_snapshot
     */
    public function test_user_self_confirm_passes_a_snapshot(): void
    {
        $calls = $this->confirmHookCalls(OrderService::class);

        $this->assertNotEmpty(
            $calls,
            'OrderService 에서 order-option.after_confirm 발화를 찾지 못했습니다 — 전제가 깨졌습니다.'
        );

        foreach ($calls as $call) {
            $this->assertSame(
                3,
                substr_count($call, ','),
                '회원 셀프 확정이 스냅샷 없이 발화합니다 — 활동 로그의 전/후 표가 비게 됩니다: '.$call
            );
        }
    }

    /**
     * 관리자 확정도 같은 인자수로 발화해야 한다.
     *
     * 스냅샷을 빼면 `ChangeDetector` 가 대조할 것이 없어 `changes` 가 빈다.
     * 같은 사건(구매확정)이 누가 눌렀는지에 따라 다른 정보량을 남겨서는 안 된다.
     *
     * @scenario confirm_path=admin_transition
     *
     * @effects admin_confirm_carries_option_snapshot
     */
    public function test_admin_confirm_passes_a_snapshot_too(): void
    {
        $calls = $this->confirmHookCalls(OrderOptionService::class);

        $this->assertNotEmpty(
            $calls,
            'OrderOptionService 에서 order-option.after_confirm 발화를 찾지 못했습니다 — 전제가 깨졌습니다.'
        );

        foreach ($calls as $call) {
            $this->assertSame(
                3,
                substr_count($call, ','),
                '관리자 구매확정이 변경 전 스냅샷 없이 발화합니다. 그래서 활동 로그의 구매확정 줄에 '
                    .'전/후 표가 통째로 비고, 회원 셀프 확정과 같은 사건인데 관리자 경로에서만 '
                    .'무엇이 무엇으로 바뀌었는지를 읽을 수 없습니다. '
                    .'실측: 옵션 231·232(회원) changes 있음 vs 옵션 245(관리자) changes 빈 문자열. '
                    .'발화 줄: '.$call
            );
        }
    }
}
