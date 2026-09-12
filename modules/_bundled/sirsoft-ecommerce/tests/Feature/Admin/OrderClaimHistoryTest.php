<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 스코프 클레임 내역 API Feature 테스트 (S6c — D20)
 *
 * 검증 축:
 * - 그룹 응답 구조 계약 — `counts` 4유형 + `claims`(신청서가 상품 배열을 품는 그룹 구조)
 * - 취소 발 환불신청서의 환불 축 제외 (취소 축이 대표)
 * - 상품 행 정규화 금액 축(`amount`/`amount_type`) — 교환 차액 부호 판정
 * - `detail_url` — 네 유형 모두 클레임 상세 경로 (취소도 조회 전용 상세가 열린다)
 * - 권한 403
 */
class OrderClaimHistoryTest extends ModuleTestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);
    }

    protected function url(Order $order): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/orders/{$order->id}/claims";
    }

    /**
     * 반품 신청서 + 상품 행 N개를 직접 생성합니다.
     */
    protected function makeReturnWithItems(Order $order, int $itemCount = 1, ReturnStatusEnum $status = ReturnStatusEnum::REQUESTED): OrderReturn
    {
        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_status' => $status,
        ]);

        for ($i = 0; $i < $itemCount; $i++) {
            $option = OrderOptionFactory::new()->create(['order_id' => $order->id]);
            OrderReturnOptionFactory::new()->create([
                'order_return_id' => $return->id,
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'option_status' => $status,
                'return_quantity' => 1,
                'original_quantity' => 2,
                'unit_price' => 10000,
                'subtotal_amount' => 10000,
            ]);
        }

        return $return;
    }

    /**
     * @effects order_claims_response_groups_items_under_each_request
     */
    public function test_order_claims_returns_grouped_structure_with_counts(): void
    {
        $order = OrderFactory::new()->create();

        // 반품 1건 (상품 2행 — 그룹 구조 재료), 취소 1건, 취소 발 환불 1건, 직접 환불 1건
        $this->makeReturnWithItems($order, 2);

        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::PARTIAL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);

        OrderRefund::create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_source_type' => RefundSourceTypeEnum::CANCEL,
            'refund_number' => 'RF-TEST-CANCEL-'.uniqid(),
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 5000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF-TEST-DIRECT-'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 3000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        // 다른 주문의 클레임은 실리지 않아야 한다 (주문 스코프)
        $this->makeReturnWithItems(OrderFactory::new()->create());

        $response = $this->actingAs($this->admin)->getJson($this->url($order))->assertOk();

        // counts — 출처로 거르지 않는다 (#599 후속 D6).
        //
        // 취소신청서와 환불신청서는 같은 사건의 두 사본이 아니라 다른 사실을 기록하는 별개
        // 문서다. 취소신청서는 "무엇이 주문에서 빠졌는가", 환불신청서는 "얼마를 어떤 수단으로
        // 돌려줬는가" 를 말한다. 한쪽을 이력에서 지우면 운영자는 그 주문의 환불 근거를 이력
        // 화면에서 볼 수 없고, 금액 분쟁 때 물어볼 문서가 사라진다.
        $response->assertJsonPath('data.counts.exchange', 0)
            ->assertJsonPath('data.counts.return', 1)
            ->assertJsonPath('data.counts.refund', 2)
            ->assertJsonPath('data.counts.cancel', 1);

        $claims = $response->json('data.claims');
        $this->assertCount(4, $claims);

        // 그룹 구조 — 신청서가 상품 배열을 품는다 (프론트가 rowspan 계산)
        $return = collect($claims)->firstWhere('claim_type', 'return');
        $this->assertNotNull($return);
        $this->assertCount(2, $return['items']);
        $this->assertSame(1, $return['items'][0]['quantity']);
        $this->assertSame(2, $return['items'][0]['original_quantity']);
        $this->assertArrayHasKey('status_label', $return);
        $this->assertArrayHasKey('requested_at_formatted', $return);
        $this->assertArrayHasKey('requester_name', $return);
        $this->assertArrayHasKey('reason_label', $return);

        // 취소 발 환불신청서도 claims 목록에 선다 (D6)
        $refundNumbers = collect($claims)->where('claim_type', 'refund')->pluck('claim_number');
        $this->assertCount(2, $refundNumbers);
        $this->assertTrue(
            $refundNumbers->contains(fn (string $number): bool => str_starts_with($number, 'RF-TEST-DIRECT-')),
            '직접 환불신청서가 이력에 있어야 한다',
        );
        $this->assertTrue(
            $refundNumbers->contains(fn (string $number): bool => str_starts_with($number, 'RF-TEST-CANCEL-')),
            '취소 발 환불신청서가 이력에 있어야 한다',
        );
    }

    public function test_order_claims_normalizes_item_amounts_by_type(): void
    {
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->create(['order_id' => $order->id]);
        $optionEqual = OrderOptionFactory::new()->create(['order_id' => $order->id]);

        $exchange = OrderExchangeFactory::new()->create(['order_id' => $order->id]);

        // 고가 교환 (차액 +3,000 — 수량 반영 소계) / 동일가 교환 (차액 0)
        OrderExchangeOptionFactory::new()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'unit_price' => 29000,
            'new_option_snapshot' => ['option_name' => ['ko' => '블랙/L'], 'option_value' => ['ko' => '색상: 블랙, 사이즈: L']],
            'new_unit_price' => 32000,
            'price_difference' => 3000,
        ]);
        OrderExchangeOptionFactory::new()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $optionEqual->id,
            'unit_price' => 29000,
            'new_unit_price' => 29000,
            'price_difference' => 0,
        ]);

        $this->makeReturnWithItems($order);

        $claims = $this->actingAs($this->admin)->getJson($this->url($order))->assertOk()->json('data.claims');

        $exchangeRow = collect($claims)->firstWhere('claim_type', 'exchange');
        $amounts = collect($exchangeRow['items'])->keyBy('amount_type');

        // 교환 — 방향은 amount_type 이 나르고 amount 는 크기만 싣는다.
        // 캐스팅 없이 단언한다: 0자리 통화(KRW)는 정수로 나가야 하고, raw float 로 새면
        // 같은 행의 형제 금액(subtotal_amount 등)과 타입이 갈린다.
        $this->assertSame(3000, $amounts['charge']['amount']);
        $this->assertSame(0, $amounts['none']['amount']);
        // 표기 문자열은 상점 통화 설정이 정하므로 리터럴로 묶지 않는다 — 이 단언이 붙드는
        // 계약은 "크기만 싣는다" 이므로 음수 부호가 없다는 것이다.
        $this->assertStringNotContainsString('-', $amounts['charge']['amount_formatted']);

        // 반품 — 대상 금액(refund 방향)
        $returnRow = collect($claims)->firstWhere('claim_type', 'return');
        $this->assertSame('refund', $returnRow['items'][0]['amount_type']);
        $this->assertSame(10000, $returnRow['items'][0]['amount']);
    }

    /**
     * 신청번호가 여는 상세 경로는 서버가 만든다. 취소도 예외가 아니다 — 종전에는 취소만 null 이라
     * 주문상세에서는 링크가 빠지고, 클레임 목록 취소 탭의 번호는 404 로 이어졌다.
     *
     * @effects order_claims_response_detail_url_for_every_type
     */
    public function test_order_claims_detail_url_is_the_claim_path_for_every_type(): void
    {
        $order = OrderFactory::new()->create();
        $return = $this->makeReturnWithItems($order);

        OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::REQUESTED,
            'cancel_reason_type' => 'change_of_mind',
            'items_snapshot' => [],
        ]);

        $claims = $this->actingAs($this->admin)->getJson($this->url($order))->assertOk()->json('data.claims');

        $returnRow = collect($claims)->firstWhere('claim_type', 'return');
        $this->assertSame('/admin/ecommerce/claims/'.$return->return_number, $returnRow['detail_url']);

        // 취소도 같은 상세 화면이 번호로 연다 (조회 전용)
        $cancelRow = collect($claims)->firstWhere('claim_type', 'cancel');
        $this->assertSame('/admin/ecommerce/claims/'.$cancelRow['claim_number'], $cancelRow['detail_url']);
    }

    public function test_order_claims_are_ordered_by_request_time_descending(): void
    {
        $order = OrderFactory::new()->create();

        // 유형이 섞인 3건을 접수 시각 오름차순으로 심는다 — 응답은 그 역순이어야 한다.
        // 평면 배열(판정 ⑤)이라 정렬이 곧 화면의 표시 순서다: 서브탭은 이 배열을
        // claim_type 으로 거르기만 하므로, 유형 안의 순서도 이 정렬에 종속된다.
        $oldest = $this->makeReturnWithItems($order);
        $oldest->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $middle = OrderExchangeFactory::new()->create(['order_id' => $order->id]);
        $middle->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $newest = $this->makeReturnWithItems($order);
        $newest->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $claims = $this->actingAs($this->admin)->getJson($this->url($order))->assertOk()->json('data.claims');

        $this->assertSame(
            [$newest->return_number, $middle->exchange_number, $oldest->return_number],
            array_column($claims, 'claim_number'),
        );
    }

    public function test_order_claims_requires_claims_read_permission(): void
    {
        $order = OrderFactory::new()->create();
        $noPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $this->actingAs($noPermission)->getJson($this->url($order))->assertForbidden();
    }

    /**
     * @effects order_claims_response_amount_carries_magnitude_only
     */
    public function test_low_price_exchange_reports_refund_direction_without_negative_amount(): void
    {
        // 저가 교환(차액 음수)은 방향이 환불이다. 부호를 amount 에 남기면 같은 `refund`
        // 라벨이 반품에서는 양수, 저가 교환에서는 음수가 되어 화면이 계약대로 유형 분기 없이
        // 그릴 때 이중 부호(`-2,000원 환불`)가 된다 — 방향은 amount_type 이 단독으로 나른다.
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->create(['order_id' => $order->id]);
        $exchange = OrderExchangeFactory::new()->create(['order_id' => $order->id]);

        OrderExchangeOptionFactory::new()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'unit_price' => 29000,
            'new_option_snapshot' => ['option_name' => ['ko' => '화이트/S'], 'option_value' => ['ko' => '색상: 화이트, 사이즈: S']],
            'new_unit_price' => 27000,
            'price_difference' => -2000,
        ]);

        $claims = $this->actingAs($this->admin)->getJson($this->url($order))->assertOk()->json('data.claims');
        $row = collect($claims)->firstWhere('claim_type', 'exchange')['items'][0];

        $this->assertSame('refund', $row['amount_type']);
        $this->assertSame(2000, $row['amount'], 'amount 는 크기만 싣는다 (부호는 amount_type)');
        $this->assertStringNotContainsString('-', $row['amount_formatted'], '표기에 음수 부호가 남아 이중 부호가 된다');
        $this->assertStringContainsString('2,000', $row['amount_formatted']);

        // 원본 필드는 부호를 그대로 유지한다 — 정규화 축은 추가 표현이지 대체가 아니다.
        $this->assertSame(-2000, $row['price_difference']);
    }

    public function test_order_claims_preserve_claim_item_field_vocabulary(): void
    {
        // 판정 ② — 유저·관리자 상품 행이 같은 ClaimItemResource 어휘를 공유한다(N벌 금지).
        // 어휘가 바뀌면 D20 을 그리는 화면과 유저 상세가 갈라지는데, 기존 단언은
        // quantity/original_quantity 2개뿐이라 나머지가 이름만 바뀌어도 green 이었다.
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->create(['order_id' => $order->id]);
        $exchange = OrderExchangeFactory::new()->create(['order_id' => $order->id]);

        OrderExchangeOptionFactory::new()->create([
            'order_exchange_id' => $exchange->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'unit_price' => 29000,
            'new_option_snapshot' => ['option_name' => ['ko' => '블랙/L'], 'option_value' => ['ko' => '색상: 블랙, 사이즈: L']],
            'new_unit_price' => 32000,
            'price_difference' => 3000,
        ]);

        $claims = $this->actingAs($this->admin)->getJson($this->url($order))->assertOk()->json('data.claims');
        $row = collect($claims)->firstWhere('claim_type', 'exchange')['items'][0];

        foreach (['product_option_name', 'new_option_label', 'new_unit_price', 'original_quantity'] as $field) {
            $this->assertArrayHasKey($field, $row, $field.' 어휘가 사라졌습니다');
        }

        // 픽스처는 서비스가 실제로 쓰는 스냅샷 형태여야 한다 — 예전 픽스처는 어느 쓰기 경로도
        // 만든 적 없는 `option_label` 키를 담아, 같은 키를 읽던 리소스의 결함을 가려 주고 있었다
        // (실데이터에서는 이 값이 항상 null 이었다 — 2026-09-01 4차 계획 검증 브라우저 실측).
        $this->assertSame('색상: 블랙, 사이즈: L', $row['new_option_label']);
        $this->assertSame(32000, $row['new_unit_price']);
    }

    public function test_cancel_rows_carry_normalized_amounts_from_cancel_options(): void
    {
        // 취소 유형의 items 축은 augmentItem 의 default 분기가 담당하는데, 기존 테스트는
        // 취소를 items_snapshot=[] 로만 만들어 그 분기가 한 번도 실행되지 않았다.
        // 실서비스는 OrderCancellationService 가 취소 옵션 행을 만든다.
        $order = OrderFactory::new()->create();
        $option = OrderOptionFactory::new()->create([
            'order_id' => $order->id,
            'unit_price' => 12000,
            'quantity' => 2,
        ]);

        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CL-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::PARTIAL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'change_of_mind',
            'cancel_amount' => 12000,
            'items_snapshot' => [],
        ]);

        OrderCancelOption::create([
            'order_cancel_id' => $cancel->id,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'cancel_quantity' => 1,
            'original_quantity' => 2,
            'unit_price' => 12000,
            'subtotal_amount' => 12000,
            'option_status' => CancelOptionStatusEnum::COMPLETED,
        ]);

        $claims = $this->actingAs($this->admin)->getJson($this->url($order))->assertOk()->json('data.claims');
        $cancelRow = collect($claims)->firstWhere('claim_type', 'cancel');

        $this->assertNotEmpty($cancelRow['items'], '취소 상품 행이 비어 있습니다');
        $row = $cancelRow['items'][0];
        $this->assertSame('refund', $row['amount_type']);
        $this->assertSame(12000, $row['amount']);
        $this->assertStringContainsString('12,000', $row['amount_formatted']);
    }

    public function test_order_claims_resolve_requester_name_and_reason_label(): void
    {
        // withAdminFields()/reasonLabels 묶음 주입은 키 존재만 단언돼 있었다 — 값이 비어도
        // green 이라 주입이 사문화돼도 드러나지 않는다.
        $order = OrderFactory::new()->create();
        // 사유 라벨은 카탈로그에서 해석된다 — 코드가 카탈로그에 없으면 원문으로 폴백하므로
        // 주입이 죽어도 값이 채워진 것처럼 보인다. 실재하는 코드를 심어 해석을 붙든다.
        ClaimReason::create([
            'type' => 'return',
            'code' => 'changed_mind',
            'name' => ['ko' => '단순 변심', 'en' => 'Changed mind'],
            'fault_type' => 'customer',
            'is_user_selectable' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        // 주문자명은 주문이 아니라 배송지에 있다 — 클레임 테이블의 신청자 축은 회원 ID 뿐이라
        // 비회원 신청 건은 이름을 주문 배송지에서만 가져올 수 있다(ClaimResource 주석).
        OrderAddressFactory::new()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'orderer_name' => '김주문',
        ]);
        $return = $this->makeReturnWithItems($order);

        $claims = $this->actingAs($this->admin)->getJson($this->url($order))->assertOk()->json('data.claims');
        $row = collect($claims)->firstWhere('claim_type', 'return');

        $this->assertSame('김주문', $row['requester_name'], 'withAdminFields() 주입이 사문화됐습니다');
        // 표시 언어는 실행 로케일이 정하므로 두 이름 중 하나면 해석된 것이다 (코드 원문이면 폴백).
        $this->assertContains($row['reason_label'], ['단순 변심', 'Changed mind'], '사유 라벨이 카탈로그에서 해석되지 않았습니다');
    }

    public function test_order_claims_returns_404_for_unknown_order(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders/999999/claims')
            ->assertNotFound();
    }
}
