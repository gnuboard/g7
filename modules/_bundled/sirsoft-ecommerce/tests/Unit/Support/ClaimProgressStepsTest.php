<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Support\ClaimProgressSteps;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 진행단계 배열 단일 출처(`ClaimProgressSteps`) 계약
 *
 * 관리자 상세만 갖던 배열을 회원·비회원 상세도 같은 자리에서 받는다. 배열이 두 곳에서
 * 만들어지면 한 화면만 낡은 채 남으므로, 관리자 서비스는 이 지원 클래스에 위임해야 한다.
 * 칸마다 아이콘·색(variant)은 상태 Enum 이 정한 값을 그대로 실어야 뱃지와 그림이 같다.
 *
 * @scenario claim_type=exchange, claim_type=cancel
 *
 * @effects claim_progress_steps_carry_icon_and_variant,
 *   claim_progress_steps_single_source_for_admin_and_user,
 *   claim_progress_cancel_has_two_stages
 */
class ClaimProgressStepsTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * @effects claim_progress_steps_carry_icon_and_variant
     */
    public function test_every_step_carries_the_icon_and_variant_of_its_status(): void
    {
        $order = $this->deliveredClaimOrder();
        $exchange = app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL,
        ]);

        $steps = ClaimProgressSteps::for($exchange);

        $this->assertNotEmpty($steps);

        foreach ($steps as $step) {
            $case = ExchangeStatusEnum::from($step['value']);

            $this->assertSame($case->icon(), $step['icon'], "{$step['value']} 칸의 아이콘은 상태 Enum 이 정한 값이어야 합니다.");
            $this->assertSame($case->variant(), $step['variant'], "{$step['value']} 칸의 색은 상태 뱃지와 같은 variant 여야 합니다.");
            $this->assertSame($case->label(), $step['label']);
            $this->assertContains($step['state'], ['done', 'current', 'upcoming', 'off_path']);
        }
    }

    /**
     * @effects claim_progress_steps_single_source_for_admin_and_user
     */
    public function test_admin_service_delegates_to_the_same_array(): void
    {
        $order = $this->deliveredClaimOrder();
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        app(OrderReturnService::class)->transition($return, ReturnStatusEnum::APPROVED);
        $return = $return->fresh();

        $this->assertSame(
            ClaimProgressSteps::for($return),
            app(AdminClaimService::class)->progressSteps($return),
            '관리자 응답의 진행단계는 회원 응답과 같은 배열이어야 합니다 — 두 곳에서 만들면 한쪽만 낡습니다.'
        );
        $this->assertSame('approved', collect(ClaimProgressSteps::for($return))->firstWhere('state', 'current')['value']);
    }

    /**
     * @effects claim_progress_cancel_has_two_stages
     */
    public function test_cancel_claims_have_two_stages_and_no_off_path(): void
    {
        $requested = new OrderCancel;
        $requested->forceFill(['cancel_status' => CancelStatusEnum::REQUESTED->value, 'cancelled_at' => null]);

        $steps = ClaimProgressSteps::for($requested);

        $this->assertSame(['requested', 'completed'], array_column($steps, 'value'));
        $this->assertSame(['current', 'upcoming'], array_column($steps, 'state'));
        $this->assertSame(['status-request', 'status-completed'], array_column($steps, 'icon'));

        $completed = new OrderCancel;
        $completed->forceFill(['cancel_status' => CancelStatusEnum::COMPLETED->value, 'cancelled_at' => now()]);

        $this->assertSame(['done', 'current'], array_column(ClaimProgressSteps::for($completed), 'state'));
        $this->assertFalse(CancelStatusEnum::REQUESTED->isOffProgressPath());
        $this->assertFalse(CancelStatusEnum::COMPLETED->isOffProgressPath());
    }
}
