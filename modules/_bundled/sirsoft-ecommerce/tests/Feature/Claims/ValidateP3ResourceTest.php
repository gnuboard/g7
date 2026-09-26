<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderOptionResource;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimRequestService;
use Modules\Sirsoft\Ecommerce\Services\ClaimQuantityGuard;
use Modules\Sirsoft\Ecommerce\Support\ClaimOutstanding;
use Modules\Sirsoft\Ecommerce\Support\EarnedPointsFormatter;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 응답 리소스·공용 산식 결함 수정 검증 (#599 validate P3 — 06 #8·minor)
 *
 * 검증 축:
 * - 옵션 리소스의 클레임 점유 수량을 부모가 한 번에 구해 주입한다(옵션당 3쿼리 → 0).
 * - 신청내역 목록 행은 상세 전용 판정(계좌 필요·PG 폴백)을 하지 않는다(N+1).
 * - 신청서 응답에 환불 우선순위(`refund_priority`)·신청자 회원 uuid(`requester_user_uuid`)를 싣는다.
 * - 비교 스냅샷의 적립 마일리지를 포인트 표기로 채우고, 단위 글자는 다국어 키가 정한다.
 * - 미수납 산식·취소 아이템 변환이 한 곳에 모인다.
 */
class ValidateP3ResourceTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 통화·사유를 심습니다.
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
     * 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 부모가 주입한 점유 수량을 쓰면 옵션 리소스가 쿼리를 내지 않고, 일괄 산정이 단건과 같은지 검증합니다.
     *
     * 수정 전: 옵션 리소스가 행마다 `ClaimQuantityGuard::availableQuantity()` 를 불러 옵션당 3쿼리가
     * 나가고, 일괄 산정(`lockedQuantities`)·주입 통로(`withClaimLockedQuantity`)가 없다.
     *
     * @scenario defect=order_option_claim_quantity_is_batched
     *
     * @effects order_option_claim_quantity_is_batched
     *
     * @return void 반환값 없음
     */
    public function test_order_option_locked_quantity_is_batched_and_injected(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 3);
        $option = $order->options->first();
        $this->makeDirectRefund($order, $option, 2);

        $guard = app(ClaimQuantityGuard::class);
        $this->assertSame([$option->id => $guard->lockedQuantity($option->id)], $guard->lockedQuantities([$option->id]));

        // 클레임 점유 집계 쿼리만 센다 — 옵션 리소스는 리뷰·권한 판정으로 다른 쿼리도 낸다.
        $claimQueries = 0;
        DB::listen(function ($query) use (&$claimQueries) {
            if (preg_match('/order_(exchange|return|refund)_options/', $query->sql)) {
                $claimQueries++;
            }
        });

        $data = (new OrderOptionResource($option))
            ->withClaimLockedQuantity(2)
            ->toArray(Request::create('/'));

        $this->assertSame(1, $data['available_quantity']);
        $this->assertSame(2, $data['locked_quantity']);
        $this->assertSame(0, $claimQueries, '주입한 점유 수량이 있는데도 옵션 리소스가 점유 집계를 다시 냈다');
    }

    /**
     * 목록 표현의 환불신청서 행이 상세 전용 판정 두 값을 싣지 않는지 검증합니다.
     *
     * 수정 전: 목록 행마다 주문·결제행을 읽어 `refund_account_required`·`refund_fell_back_to_manual` 을
     * 계산한다(N+1) — 값이 bool 로 나온다.
     *
     * @scenario defect=claim_list_rows_skip_detail_only_judgements
     *
     * @effects claim_list_rows_skip_detail_only_judgements
     *
     * @return void 반환값 없음
     */
    public function test_claim_list_rows_skip_detail_only_judgements(): void
    {
        $order = $this->deliveredClaimOrder();
        $refund = $this->makeDirectRefund($order, $order->options->first(), 1, RefundStatusEnum::APPROVED);

        $data = (new ClaimResource($refund->fresh(['order'])))->forList()->toArray(Request::create('/'));

        $this->assertNull($data['refund_account_required']);
        $this->assertNull($data['refund_fell_back_to_manual']);
    }

    /**
     * 신청서 응답이 환불 우선순위와 신청자 회원 uuid 를 싣는지 검증합니다.
     *
     * 수정 전: 두 키가 없다 — 수정 화면은 환불액 구성으로 우선순위를 추측하고, 신청자 링크는 숫자 id 라 404 다.
     *
     * @scenario defect=claim_response_carries_refund_priority_and_requester_uuid
     *
     * @effects claim_response_carries_refund_priority_and_requester_uuid
     *
     * @return void 반환값 없음
     */
    public function test_claim_response_carries_refund_priority_and_requester_uuid(): void
    {
        $customer = $this->createUser();
        $order = $this->deliveredClaimOrder();
        $order->forceFill(['user_id' => $customer->id])->save();
        $refund = $this->makeDirectRefund($order->fresh(['options']), $order->options->first(), 1);

        $claim = $refund->fresh(['order']);
        $claim->setAttribute('refund_priority', 'points_first');

        $data = (new ClaimResource($claim))->withAdminFields()->toArray(Request::create('/'));

        $this->assertArrayHasKey('refund_priority', $data);
        $this->assertSame('points_first', $data['refund_priority']);
        $this->assertSame($customer->uuid, $data['requester_user_uuid']);
    }

    /**
     * 비교 스냅샷의 적립 마일리지가 포인트 표기로 채워지고, 단위 글자가 다국어 키를 따르는지 검증합니다.
     *
     * 수정 전: 옛 스냅샷에는 포인트 표기가 없어 화면이 통화 표기(「1,234원」)로 떨어지고, 포맷터가
     * `'P'` 를 코드에 박아 로케일이 단위를 바꿀 수 없다.
     *
     * @scenario defect=claim_comparison_formats_earned_points
     *
     * @effects claim_comparison_formats_earned_points
     *
     * @return void 반환값 없음
     */
    public function test_calculation_comparison_formats_earned_points_through_the_lang_key(): void
    {
        app('translator')->addLines(['messages.mileage.points_format' => ':points pts'], app()->getLocale(), 'sirsoft-ecommerce');

        $this->assertSame('1,234 pts', EarnedPointsFormatter::format(1234));

        $order = $this->deliveredClaimOrder();
        $refund = $this->makeDirectRefund($order, $order->options->first(), 1);
        $refund->update([
            'original_calculation_snapshot' => ['total_paid_amount' => 29000, 'total_earned_points_amount' => 1234],
            'recalculated_snapshot' => ['total_paid_amount' => 0, 'total_earned_points_amount' => 0],
        ]);

        $data = (new ClaimResource($refund->fresh(['order'])))->toArray(Request::create('/'));

        $this->assertSame('1,234 pts', $data['calculation_comparison']['original']['total_earned_points_amount_formatted']);
        $this->assertSame('0 pts', $data['calculation_comparison']['recalculated']['total_earned_points_amount_formatted']);
    }

    /**
     * 미수납 산식과 관리자 취소의 아이템 변환이 서비스·공용 헬퍼 한 곳에 있는지 검증합니다.
     *
     * 수정 전: 미수납 식이 결제 서비스와 목록 리소스에 사본으로 있고, 취소 아이템 변환은 관리자
     * 컨트롤러의 private 메서드였다(두 클래스가 없다).
     *
     * @scenario defect=outstanding_and_cancel_items_have_single_sources
     *
     * @effects outstanding_and_cancel_items_have_single_sources
     *
     * @return void 반환값 없음
     */
    public function test_outstanding_formula_and_cancel_items_have_single_sources(): void
    {
        $this->assertSame(0.0, ClaimOutstanding::amount(3000, 3000.004));
        $this->assertSame(1000.0, ClaimOutstanding::amount(3000, 2000));
        $this->assertSame(0.0, ClaimOutstanding::amount(3000, 5000));

        $order = $this->deliveredClaimOrder(quantity: 2);
        $option = $order->options->first();

        $service = app(AdminClaimRequestService::class);

        $this->assertSame(
            [['order_option_id' => $option->id, 'quantity' => 2]],
            $service->toCancelItems($order, true, []),
        );
        $this->assertSame(
            [['order_option_id' => $option->id, 'quantity' => 1]],
            $service->toCancelItems($order, false, [['order_option_id' => (string) $option->id, 'cancel_quantity' => '1']]),
        );

        $option->forceFill(['option_status' => OrderStatusEnum::CANCELLED])->save();
        $this->assertSame([], $service->toCancelItems($order->fresh(['options']), true, []));
    }
}
