<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 전이 활동로그 전수 보강 테스트 (#599 후속 §7-1 · D16)
 *
 * 로그는 **훅 발행 → 리스너** 한 축뿐이다 — 모델 옵저버도 자동 기록도 없으므로
 * `훅이 없으면 로그도 없다` 가 예외 없이 성립한다. 그래서 이 파일은 "그 전이 지점에서 훅이
 * 실제로 발화하는가" 를 잠근다.
 *
 * 함께 잠그는 것이 **라벨**이다. 라벨 키 해석 메서드(`labelKey()`)가 없으면 활동로그 리스너가
 * 정의되지 않은 메서드를 불러 잡이 통째로 죽는데, 리스너는 큐 잡으로 돌아가므로 그 실패는
 * 화면 어디에도 드러나지 않고 **해당 전이의 로그만 조용히 사라진다**. 환불·취소 축이 실제로
 * 그 상태였다.
 */
class ClaimActivityLogTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected OrderRefundRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->service = app(OrderRefundRequestService::class);

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true,
            'transaction_id' => 'TEST_REFUND_TXN',
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 전량 신청 페이로드를 만듭니다.
     *
     * @param  Order  $order  주문
     * @return array 페이로드
     */
    protected function payload(Order $order): array
    {
        return [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => (int) $order->options->first()->quantity]],
            'reason_code' => 'changed_mind',
            'reason_detail' => '단순 변심',
            'requested_by' => $order->user_id,
        ];
    }

    // ================================================================
    // 라벨 키 — 없으면 로그 잡이 죽고 그 전이만 조용히 사라진다
    // ================================================================

    /**
     * 활동로그가 쓰는 상태 Enum 은 전부 라벨 **키** 를 낼 수 있다.
     *
     * 로그는 값을 원값으로 저장하고 라벨은 표시 시점 로케일로 해석한다 — 그래서 이미 번역된
     * 문자열(`label()`)이 아니라 키가 필요하다. 네 Enum 에 그 메서드가 없어 환불·취소 축의
     * 전이 로그가 기록 단계에서 실패하고 있었다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=none
     *
     * @effects every_claim_status_enum_exposes_a_label_key
     */
    public function test_every_claim_status_enum_exposes_a_label_key(): void
    {
        $enums = [
            RefundStatusEnum::class,
            RefundStatusEnum::class,
            CancelStatusEnum::class,
            CancelOptionStatusEnum::class,
            ExchangeStatusEnum::class,
            ReturnStatusEnum::class,
            OrderStatusEnum::class,
        ];

        foreach ($enums as $enum) {
            $this->assertTrue(
                method_exists($enum, 'labelKey'),
                $enum.' 에 labelKey() 가 없으면 활동로그 기록이 실패하고 그 전이만 조용히 사라진다',
            );

            foreach ($enum::cases() as $case) {
                $this->assertNotSame('', $case->labelKey(), $enum.'::'.$case->name.' 의 라벨 키가 비었다');
            }
        }
    }

    /**
     * 취소 축 라벨 3종이 언어팩에 있다 (R8).
     *
     * 훅만 붙이고 라벨을 두지 않으면 활동로그 문구가 **빈 채로** 나온다 — 오류가 아니라
     * 빈칸이라 릴리스 뒤에야 드러난다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=none
     *
     * @effects cancel_axis_activity_log_labels_exist
     */
    public function test_the_cancel_axis_activity_log_labels_exist(): void
    {
        $keys = [
            'claim_cancel_request',
            'claim_cancel_status_change',
            'claim_cancel_option_status_change',
            'claim_refund_option_status_change',
        ];

        foreach (['ko', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                $full = 'sirsoft-ecommerce::activity_log.description.'.$key;
                $this->assertNotSame($full, __($full), "{$locale} 로케일에 {$key} 라벨이 없다 (R8)");
            }
        }
    }

    // ================================================================
    // 전이 지점별 훅 발화 (§7-1)
    // ================================================================

    /**
     * 자동 전이 한 번이 환불 축 전이를 **단계마다** 낸다 (D16).
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects auto_advance_logs_every_refund_transition
     */
    public function test_auto_advance_logs_every_refund_transition(): void
    {
        $order = $this->paidClaimOrder();

        $refundSteps = [];
        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            function ($refund, $previous = null, $target = null) use (&$refundSteps) {
                $refundSteps[] = $previous.'->'.$target;
            },
        );

        $this->service->request($order, $this->payload($order));

        $this->assertContains('requested->approved', $refundSteps);
        $this->assertContains('approved->completed', $refundSteps);
    }

    /**
     * 환불 **신청상품** 전이가 자기 축 훅으로 남는다 (C5·C6).
     *
     * 이 훅은 발행·구독 양쪽에 모두 없었다 — 환불 신청상품이 반려·철회·완료로 옮겨 가도
     * 어떤 줄도 남지 않았다(교환·반품에는 있는 축이다).
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects refund_option_status_transition_is_logged
     */
    public function test_the_refund_item_transition_is_logged(): void
    {
        $order = $this->paidClaimOrder();

        $optionChanges = [];
        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.option_status_changed',
            function ($refund, $changes = [], $target = null) use (&$optionChanges) {
                $optionChanges[] = ['target' => $target, 'count' => count($changes)];
            },
        );

        $this->service->request($order, $this->payload($order));

        $this->assertNotEmpty($optionChanges, '환불 신청상품 전이가 훅으로 남아야 한다 (C6)');
        $this->assertSame(RefundStatusEnum::COMPLETED->value, $optionChanges[0]['target']);
        $this->assertGreaterThan(0, $optionChanges[0]['count']);
    }

    /**
     * 취소 축의 접수·전이·상품 전이 세 훅이 모두 발화한다 (B3·B4·C7·C8).
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects cancel_axis_fires_request_status_and_option_hooks
     */
    public function test_the_cancel_axis_fires_all_three_hooks(): void
    {
        $order = $this->paidClaimOrder();

        $fired = ['requested' => 0, 'status' => 0, 'option' => 0];

        HookManager::addAction('sirsoft-ecommerce.claim.cancel.requested', function () use (&$fired) {
            $fired['requested']++;
        });
        HookManager::addAction('sirsoft-ecommerce.claim.cancel.status_changed', function () use (&$fired) {
            $fired['status']++;
        });
        HookManager::addAction('sirsoft-ecommerce.claim.cancel.option_status_changed', function () use (&$fired) {
            $fired['option']++;
        });

        $this->service->request($order, $this->payload($order));

        $this->assertSame(1, $fired['requested'], '취소신청서 접수 훅 (B3)');
        $this->assertSame(1, $fired['status'], '취소신청서 전이 훅 (B4)');
        $this->assertSame(1, $fired['option'], '취소 신청상품 전이 훅 (C7·C8)');
    }

    /**
     * 주문 상태 동기화가 끌고 간 상품 전이가 훅으로 남는다 (A3).
     *
     * 이 경로는 관계 빌더의 raw update 라 `OrderOptionService` 의 어떤 훅도 지나지 않아
     * **한 줄도 남지 않았다**. 주문 헤더 줄만 보고는 어느 상품이 함께 움직였는지 알 수 없다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=none
     *
     * @effects order_status_sync_logs_the_item_transitions_it_drags
     */
    public function test_order_status_sync_logs_the_item_transitions_it_drags(): void
    {
        $captured = [];
        HookManager::addAction(
            'sirsoft-ecommerce.order_option.after_sync_status',
            function ($changes = [], $target = null, $source = '') use (&$captured) {
                $captured[] = ['count' => count($changes), 'target' => $target, 'source' => $source];
            },
        );

        // 결제완료 전이는 주문상품 상태를 raw update 로 함께 옮긴다 — 그 지점이 A3 다.
        // 픽스처 헬퍼(`paidClaimOrder`)는 상태를 직접 써 넣어 그 경로를 지나지 않으므로,
        // 실제 결제완료 서비스를 태워야 이 훅이 발화한다.
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(29000, 1, $policy);
        $order = $this->attachPolicyShippings($order, $policy);
        $order->forceFill(['order_status' => OrderStatusEnum::PENDING_PAYMENT])->save();
        $order->options->first()->forceFill(['option_status' => OrderStatusEnum::PENDING_PAYMENT])->save();

        app(OrderProcessingService::class)
            ->completePayment($order->fresh(['options', 'payment', 'shippings']), []);

        $this->assertNotEmpty($captured, '주문 상태 동기화가 끌고 간 상품 전이가 훅으로 남아야 한다 (A3)');
        $this->assertGreaterThan(0, $captured[0]['count']);
        $this->assertSame('order_status_sync', $captured[0]['source']);
    }

    /**
     * 취소 축이 morph map 에 등록돼 있다 (F9).
     *
     * 빠져 있으면 활동로그가 `loggable_type` 을 FQCN 으로 저장하는데 클레임 상세의 활동로그
     * 탭은 morph 별칭(`cancel`)으로 조회하므로, 줄은 쌓이는데 화면은 **구조적으로 언제나
     * 빈 표**가 된다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=none
     *
     * @effects order_cancel_is_registered_in_the_claim_morph_map
     */
    public function test_order_cancel_is_registered_in_the_claim_morph_map(): void
    {
        $map = Relation::morphMap();

        $this->assertSame(OrderExchange::class, $map[ClaimTypeEnum::EXCHANGE->value] ?? null);
        $this->assertSame(OrderReturn::class, $map[ClaimTypeEnum::RETURN->value] ?? null);
        $this->assertSame(OrderRefund::class, $map[ClaimTypeEnum::REFUND->value] ?? null);
        $this->assertSame(
            OrderCancel::class,
            $map[ClaimTypeEnum::CANCEL->value] ?? null,
            '취소 축이 morph map 에 없으면 클레임 상세의 활동로그 탭이 구조적으로 언제나 빈다 (F9)',
        );
    }
}
