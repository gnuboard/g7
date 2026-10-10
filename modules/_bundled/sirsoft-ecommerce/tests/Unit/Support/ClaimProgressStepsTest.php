<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
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

    /**
     * 통화 설정과 클레임 사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    /**
     * 테스트 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 진행단계 칸마다 그 상태 Enum 의 아이콘·색·라벨을 싣는지 검증합니다.
     *
     * @effects claim_progress_steps_carry_icon_and_variant
     *
     * @return void 반환값 없음
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
     * 관리자 서비스가 회원 응답과 같은 진행단계 배열을 쓰는지 검증합니다.
     *
     * @effects claim_progress_steps_single_source_for_admin_and_user
     *
     * @return void 반환값 없음
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
     * 취소 신청서는 신청·완료 두 칸이고 이탈 칸이 없는지 검증합니다.
     *
     * @effects claim_progress_cancel_has_two_stages
     *
     * @return void 반환값 없음
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

    /**
     * 선교환의 진행단계는 회수 칸 없이 발송 단계로 가고, 헌 물건의 회수는 「회수」 줄이 센다(이 시험은 1개씩 갈라 행 수와 수량이 같다 — 수량 기준은 아래 시험) —
     * 대기·회수중·도착이 섞이면 줄 상태는 가장 뒤처진 대기다. 일반 교환에는 회수 줄이 없다.
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_progress_draws_a_collection_lane_by_row_count
     *
     * @return void 반환값 없음
     */
    public function test_advance_exchange_draws_a_collection_lane_by_row_count(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);
        $service = app(OrderExchangeService::class);
        $order = $this->deliveredClaimOrder(quantity: 3, exchangeFee: 0);
        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 3]],
            'reason_code' => 'size_mismatch',
        ]);

        $this->assertNull(ClaimProgressSteps::collectionLane($exchange->fresh()), '일반 교환에 회수 줄이 생겼습니다.');

        $exchange = $service->setExchangeMode($exchange->fresh(), ExchangeModeEnum::ADVANCE);
        $exchange = $service->transition($exchange->fresh(), ExchangeStatusEnum::APPROVED);
        $exchange = $service->transition($exchange, ExchangeStatusEnum::REDELIVERING, $this->claimTrackingFor(ExchangeStatusEnum::REDELIVERING));
        $carrier = $this->testClaimCarrier();
        $itemId = (int) $exchange->fresh('exchangeOptions')->exchangeOptions->first()->id;

        // 3개 중 1개는 도착, 1개는 회수중, 1개는 아직 출발 전.
        $second = $service->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 1]], $carrier->id, 'LANE-1')['split'][0]['remainder_id'];
        $service->deliverTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, $carrier->id, 'LANE-1');
        $service->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [['id' => $second, 'quantity' => 1]], $carrier->id, 'LANE-2');

        $fresh = $exchange->fresh(['exchangeOptions']);
        $lane = ClaimProgressSteps::collectionLane($fresh);

        $this->assertSame(3, $lane['total']);
        $this->assertSame(1, $lane['collected']);
        $this->assertSame(1, $lane['in_transit']);
        $this->assertSame(1, $lane['pending']);
        $this->assertSame('pending', $lane['state']);
        $this->assertNotNull($lane['due_date']);
        $this->assertFalse($lane['is_overdue']);

        $values = array_column(ClaimProgressSteps::for($fresh), 'value');
        $this->assertNotContains('collecting', $values);
        $this->assertNotContains('collected', $values);
        $this->assertSame('redelivering', collect(ClaimProgressSteps::for($fresh))->firstWhere('state', 'current')['value']);
    }

    /**
     * 회수 줄은 **수량**으로 센다 — 화면 문구가 「3개 중 2개 도착」처럼 개수를 말하기 때문이다.
     * 한 행에 담긴 3개 중 2개를 한 송장으로 보내면 행은 둘(2개·1개)로 갈리는데, 행 수로 세면
     * 처음엔 「1개 중 0개」, 도착 뒤엔 「2개 중 1개」가 되어 실제 개수와 어긋난다.
     *
     * 회귀 배경 (S1-D15, 2026-10-07 실측): 3개 교환 → 2개 회수 도착 → 「2개 중 1개 도착」.
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_collection_lane_counts_quantities_not_rows
     *
     * @return void 반환값 없음
     */
    public function test_advance_collection_lane_counts_quantities_not_rows(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);
        $service = app(OrderExchangeService::class);
        $order = $this->deliveredClaimOrder(quantity: 3, exchangeFee: 0);
        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 3]],
            'reason_code' => 'size_mismatch',
        ]);

        $exchange = $service->setExchangeMode($exchange->fresh(), ExchangeModeEnum::ADVANCE);
        $exchange = $service->transition($exchange->fresh(), ExchangeStatusEnum::APPROVED);
        $exchange = $service->transition($exchange, ExchangeStatusEnum::REDELIVERING, $this->claimTrackingFor(ExchangeStatusEnum::REDELIVERING));

        $before = ClaimProgressSteps::collectionLane($exchange->fresh(['exchangeOptions']));
        $this->assertSame(3, $before['total'], '한 행에 담긴 3개를 1개로 셌습니다.');
        $this->assertSame(3, $before['pending']);

        $carrier = $this->testClaimCarrier();
        $itemId = (int) $exchange->fresh('exchangeOptions')->exchangeOptions->first()->id;
        $service->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 2]], $carrier->id, 'LANE-Q');
        $service->deliverTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, $carrier->id, 'LANE-Q');

        $lane = ClaimProgressSteps::collectionLane($exchange->fresh(['exchangeOptions']));

        $this->assertSame(3, $lane['total'], '행 수(2)로 셌습니다.');
        $this->assertSame(2, $lane['collected']);
        $this->assertSame(1, $lane['pending']);
        $this->assertSame('pending', $lane['state']);
    }

    /**
     * 회수 줄의 반송 기한 날짜는 **보는 사람의 타임존** 날짜다 — 같은 화면의 지연 배너(`collection_due_date_formatted`)와
     * 한 날짜를 가리켜야 한다. UTC 날짜를 그대로 찍으면 한국 저녁 9시 이후 기한은 배너와 하루 어긋난다(Chrome 실측 A-4).
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_collection_lane_due_date_follows_viewer_timezone
     *
     * @return void 반환값 없음
     */
    public function test_collection_lane_due_date_uses_the_viewer_timezone(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);
        app()->instance('user_timezone', 'Asia/Seoul');
        $service = app(OrderExchangeService::class);
        $order = $this->deliveredClaimOrder(quantity: 1, exchangeFee: 0);
        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ]);
        $exchange = $service->setExchangeMode($exchange->fresh(), ExchangeModeEnum::ADVANCE);

        // UTC 2026-09-24 23:59:59 = 서울 2026-09-25 08:59:59.
        $exchange->forceFill(['collection_due_at' => Carbon::parse('2026-09-24 23:59:59', 'UTC')])->save();

        $lane = ClaimProgressSteps::collectionLane($exchange->fresh(['exchangeOptions']));

        $this->assertSame('2026-09-25', $lane['due_date']);
    }

    /**
     * 헤더 「전체 회수 완료」 시각은 헤더가 회수도착에 이를 때만 찍힌다 — 일부 상품만 도착했을 때는 비어 있고
     * 그 상품 행에만 도착 시각이 남는다. 진행단계도 헤더 상태를 따른다. (S2)
     *
     * @scenario exchange_mode=standard, stage=collection
     *
     * @effects header_arrival_stamp_waits_for_every_living_item
     *
     * @return void 반환값 없음
     */
    public function test_header_arrival_stamp_waits_for_every_living_item(): void
    {
        $service = app(OrderReturnService::class);
        $order = $this->deliveredClaimOrder(quantity: 2);
        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 2]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $itemId = (int) $return->fresh('returnOptions')->returnOptions->first()->id;

        $result = $service->transitionOption($return->fresh(['returnOptions']), [['id' => $itemId, 'quantity' => 1]], ReturnStatusEnum::COLLECTED);
        $remainderId = $result['split'][0]['remainder_id'];

        $partial = $return->fresh(['returnOptions']);
        $this->assertSame(ReturnStatusEnum::APPROVED, $partial->return_status);
        $this->assertNull($partial->collection_completed_at, '일부만 도착했는데 헤더가 「전체 회수 완료」 가 됐습니다.');
        $this->assertNotNull($partial->returnOptions->firstWhere('id', $itemId)->collected_at);
        $this->assertSame('approved', collect(ClaimProgressSteps::for($partial))->firstWhere('state', 'current')['value']);

        $service->transitionOption($partial, [$remainderId], ReturnStatusEnum::COLLECTED);

        $all = $return->fresh(['returnOptions']);
        $this->assertSame(ReturnStatusEnum::COLLECTED, $all->return_status);
        $this->assertNotNull($all->collection_completed_at);
        $this->assertSame('collected', collect(ClaimProgressSteps::for($all))->firstWhere('state', 'current')['value']);
    }
}
