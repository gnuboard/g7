<?php

declare(strict_types=1);

namespace Tests\Unit\CoreUpdate;

use App\CoreUpdate\CoreUpdateStep;
use App\Http\Middleware\MaintenanceModePage;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 웹 코어 업데이트의 **단계 계약** (#609 G6).
 *
 * 여기서 잠그는 것은 구현이 아니라 **순서가 갖는 안전 성질**이다:
 *
 * 1. 파일 적용과 업그레이드 스텝은 반드시 다른 단계다 — 한 요청에 이어 하면 구버전
 *    프로세스가 사라진 클래스를 참조한다 (§7 R25).
 * 2. 되돌리기의 전제인 백업이 파일 적용보다 **앞**에 있다 (D10 · D27).
 * 3. 점검 모드가 파일을 건드리기 **전**에 켜진다.
 * 4. 모든 단계에 화면에 낼 이름이 있다 — 없으면 진행 목록에 원시 키가 노출된다.
 *
 * 커버 축: core_update_web step_order·step_label·maintenance_exempt
 */
class CoreUpdateStepContractTest extends TestCase
{
    /**
     * 단계 순서가 안전 불변식을 만족하는지 확인합니다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects step_order_keeps_safety_invariants
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_step_order_keeps_safety_invariants(): void
    {
        $sequence = CoreUpdateStep::sequence();

        $this->assertSame(CoreUpdateStep::Preflight, $sequence[0], '사전 진단이 첫 단계여야 한다 (D26).');

        $this->assertLessThan(
            CoreUpdateStep::Apply->position(),
            CoreUpdateStep::Maintenance->position(),
            '점검 모드는 파일을 건드리기 전에 켜져야 한다.',
        );

        $this->assertLessThan(
            CoreUpdateStep::Apply->position(),
            CoreUpdateStep::Backup->position(),
            '백업이 파일 적용보다 앞에 있어야 자동 되돌리기가 성립한다 (D10).',
        );

        $this->assertLessThan(
            CoreUpdateStep::UpgradeSteps->position(),
            CoreUpdateStep::Apply->position(),
            '업그레이드 스텝은 파일 적용 **이후의 다른 요청**에서 돌아야 한다 (§7 R25).',
        );

        $this->assertTrue(
            CoreUpdateStep::UpgradeSteps->runsAfterFileReplacement(),
            '업그레이드 스텝은 파일 교체 이후 단계로 표시돼야 한다.',
        );

        $this->assertFalse(
            CoreUpdateStep::Apply->runsAfterFileReplacement(),
            '파일 적용 자신은 교체 이후 단계가 아니다 — 그 요청이 교체를 수행한다.',
        );

        $this->assertSame(CoreUpdateStep::Finalize, $sequence[count($sequence) - 1], '마무리가 마지막 단계여야 한다.');
    }

    /**
     * enum 케이스 전수가 순서 목록에 있는지 확인합니다.
     *
     * 순서는 선언 순서가 아니라 `sequence()` 가 소유하므로, 케이스를 추가하고 목록에
     * 넣지 않으면 그 단계는 **영원히 실행되지 않는다** — 오류가 아니라 조용한 누락이다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects every_case_appears_in_the_sequence
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_every_case_appears_in_the_sequence(): void
    {
        $sequence = CoreUpdateStep::sequence();

        foreach (CoreUpdateStep::cases() as $case) {
            $this->assertContains($case, $sequence, "단계 {$case->value} 가 순서 목록에 없다.");
        }

        $this->assertCount(count(CoreUpdateStep::cases()), $sequence, '순서 목록에 중복이 있다.');
    }

    /**
     * 모든 단계에 번역된 이름이 있는지 확인합니다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects every_step_has_a_translated_label
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_every_step_has_a_translated_label(): void
    {
        foreach (CoreUpdateStep::cases() as $case) {
            $key = $case->labelKey();
            $label = (string) __($key);

            $this->assertNotSame($key, $label, "단계 {$case->value} 의 이름 키가 번역되지 않았다: {$key}");
            $this->assertNotSame('', trim($label));
        }
    }

    /**
     * 점검 모드 면제 목록이 실제 라우트 URI 와 일치하는지 확인합니다.
     *
     * 업데이트는 스스로 점검 모드를 켠다. 진행·되돌리기 경로가 그 안에 갇히면 사이트를
     * 세워 놓고 이어 갈 수단이 사라진다 — 「유지보수 모드에 갇혀 복구할 수 없는 상태
     * 금지」(A-7 설계 4)가 성립하려면 두 목록이 어긋나면 안 된다. 어긋나도 오류가 아니라
     * **503 로 멈추는 진행**으로만 드러나므로 계약으로 잠근다.
     *
     * @scenario run_state=running, failing_step=none
     *
     * @effects maintenance_exempt_uris_match_the_registered_routes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_maintenance_exempt_uris_match_the_registered_routes(): void
    {
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name)) {
                continue;
            }

            if (in_array($name, [
                'api.admin.core-update.status',
                'api.admin.core-update.advance',
                'api.admin.core-update.dismiss',
                'api.admin.core-update.selection',
            ], true)) {
                $registered[] = $route->uri();
            }
        }

        sort($registered);
        $exempt = MaintenanceModePage::MAINTENANCE_EXEMPT_URIS;
        sort($exempt);

        $this->assertNotEmpty($registered, '진행·되돌리기 라우트를 찾지 못했다 — 이름이 바뀌었는지 확인할 것.');
        $this->assertSame(
            $registered,
            $exempt,
            '점검 모드 면제 목록과 실제 라우트 URI 가 어긋났다 — 진행이 503 으로 멈춘다.',
        );
    }
}
