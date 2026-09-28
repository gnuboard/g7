<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 파생 환불신청서의 사유 라벨 해석 테스트 (S17 트랙 A F-2)
 *
 * 교환·반품에서 파생된 환불신청서는 **부모의 사유 코드를 그대로 물려받는다**. 그런데 사유
 * 카탈로그는 유형별로 나뉘어 있어서, 교환에만 있는 코드(`defective_product` 등)를 환불
 * 카탈로그에서 찾으면 매칭에 실패한다. 그 폴백이 `?? $code` 라 화면에는 **원시 코드가 그대로**
 * 나온다 — 구매자 신청내역 목록의 사유 칸에 `defective_product` 가 찍힌다.
 *
 * 오류도 로그도 남지 않는다. 라벨 자리에 영문 스네이크케이스가 보이는 것이 유일한 증상이고,
 * 직접 접수한 환불(`direct`)은 정상이라 나란히 보지 않으면 드러나지 않는다.
 *
 * 카탈로그를 고르는 축은 **그 코드가 실제로 등록된 곳**, 즉 파생 출처의 유형이다.
 */
class ClaimDerivedRefundReasonLabelTest extends ModuleTestCase
{
    /**
     * 유형별 사유 카탈로그를 심습니다.
     */
    private function seedReasons(): void
    {
        $put = function (string $type, string $code, string $ko, string $fault): void {
            ClaimReason::updateOrCreate(
                ['type' => $type, 'code' => $code],
                [
                    'name' => ['ko' => $ko, 'en' => $ko],
                    'fault_type' => $fault,
                    'is_user_selectable' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                ],
            );
        };

        // 교환에만 있는 코드 — 환불 카탈로그에는 없다(시더도 넣지 않는다)
        $put(ClaimTypeEnum::EXCHANGE->value, 'defective_product', '상품 불량/파손', 'seller');

        // 반품·환불 양쪽에 있는 코드 — 유형마다 다른 문구를 쓴다
        $put(ClaimTypeEnum::RETURN->value, 'changed_mind', '반품 단순 변심', 'customer');
        $put(ClaimTypeEnum::REFUND->value, 'changed_mind', '환불 단순 변심', 'customer');
    }

    /**
     * 라벨 주입 없이(단건 상세 경로) 직렬화합니다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @return array<string, mixed> 직렬화 결과
     */
    private function serialize(OrderRefund $refund): array
    {
        return (new ClaimResource($refund))->toArray(request());
    }

    public function test_a_refund_derived_from_an_exchange_reads_the_exchange_reason_catalog(): void
    {
        $this->seedReasons();

        $refund = new OrderRefund([
            'refund_status' => RefundStatusEnum::REQUESTED,
            'reason_code' => 'defective_product',
            'refund_source_type' => RefundSourceTypeEnum::EXCHANGE,
            'refund_source_id' => 58,
        ]);

        $result = $this->serialize($refund);

        $this->assertSame('상품 불량/파손', $result['reason_label']);
        $this->assertNotSame('defective_product', $result['reason_label']);
    }

    public function test_a_refund_derived_from_a_return_reads_the_return_reason_catalog(): void
    {
        $this->seedReasons();

        $refund = new OrderRefund([
            'refund_status' => RefundStatusEnum::REQUESTED,
            'reason_code' => 'changed_mind',
            'refund_source_type' => RefundSourceTypeEnum::RETURN,
            'refund_source_id' => 54,
        ]);

        $result = $this->serialize($refund);

        // 같은 코드가 환불 카탈로그에도 있지만, 이 신청서의 사유는 반품에서 온 것이다.
        $this->assertSame('반품 단순 변심', $result['reason_label']);
    }

    public function test_a_direct_refund_still_reads_the_refund_reason_catalog(): void
    {
        $this->seedReasons();

        $refund = new OrderRefund([
            'refund_status' => RefundStatusEnum::REQUESTED,
            'reason_code' => 'changed_mind',
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
        ]);

        $result = $this->serialize($refund);

        $this->assertSame('환불 단순 변심', $result['reason_label']);
    }

    public function test_an_unknown_code_still_falls_back_to_the_raw_code(): void
    {
        $this->seedReasons();

        $refund = new OrderRefund([
            'refund_status' => RefundStatusEnum::REQUESTED,
            'reason_code' => 'never_registered',
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
        ]);

        $result = $this->serialize($refund);

        // 어느 카탈로그에도 없으면 종전대로 코드를 그대로 둔다 — 라벨을 지어내지 않는다.
        $this->assertSame('never_registered', $result['reason_label']);
    }
}
