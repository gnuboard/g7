<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Support\ClaimUrl;

/**
 * 주문 스코프 클레임 내역 리소스 (D20 — 관리자 주문상세 '클레임 내역' 섹션)
 *
 * `ClaimResource` 의 상세 표현(신청서 + 상품 행 그룹)을 그대로 상속하고, 이 화면이
 * 추가로 요구하는 축 두 가지만 얹는다:
 *
 * - `detail_url` — 신청번호가 새 창으로 여는 관리자 상세 경로. 네 유형 모두 같은 화면
 *   (`/admin/ecommerce/claims/{번호}`)으로 이어지며 서버가 번호에서 유형을 해석한다. 취소도
 *   열린다 — 취소는 조회 전용 상세라 전이·송장 축 없이 취소 구분·환불 상태·활동 로그를 보인다.
 * - 상품 행의 `amount`/`amount_formatted`/`amount_type` — 네 유형의 금액 축(교환 차액 /
 *   반품·취소 대상 금액 / 환불액)을 한 컬럼으로 그리기 위한 정규화 값. `amount` 는 언제나
 *   **크기(0 이상)** 이고 방향은 `amount_type` 이 단독으로 나른다. 원본 필드
 *   (`price_difference`·`subtotal_amount`·`refund_amount`)는 그대로 남는다 — 화면이
 *   유형 분기 없이 금액 컬럼을 그리게 하는 추가 표현이지 대체가 아니다.
 *
 * 신청서 그룹 구조(신청서 → items 배열)는 부모가 이미 보장한다 — PC 의 rowspan 병합과
 * 모바일 카드 묶음이 같은 응답으로 그려진다(D20).
 */
class OrderClaimHistoryResource extends ClaimResource
{
    /** @var array<string, array<int, string>> 출처 유형 → (신청서 id → 번호) — 환불 행의 원 신청서 번호(보완 5) */
    protected array $sourceNumbers = [];

    /**
     * 같은 주문의 반품·교환 번호표를 주입합니다. (보완 5 — 추가 쿼리 없이 환불 행에 원 신청서 번호를 싣는다)
     *
     * @param  array<string, array<int, string>>  $sourceNumbers  출처 유형(return|exchange) → (id → 번호)
     * @return static 체이닝용 자기 자신
     */
    public function withSourceNumbers(array $sourceNumbers): static
    {
        $this->sourceNumbers = $sourceNumbers;

        return $this;
    }

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 응답 배열
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $type = $this->resource->claimType();

        $data['detail_url'] = $this->resolveDetailUrl($data['claim_number'] ?? null);
        // 「환불 미접수」 뱃지(D9) — 클레임 목록 필터 `pending_refund` 와 같은 술어다(금액 0 판정은 상세가 한다).
        $data['has_pending_refund'] = $this->hasPendingRefund();
        // 환불 행의 원 신청서 번호(보완 5) — 반품·교환에서 생긴 환불이면 그 신청서 번호, 아니면 null.
        $data['source_claim_number'] = $this->resource instanceof OrderRefund
            ? ($this->sourceNumbers[$this->resource->refund_source_type?->value ?? ''][(int) $this->resource->refund_source_id] ?? null)
            : null;

        // 부모의 items 는 ClaimItemResource 컬렉션(통화 주입 완료)이다 — 배열로 풀어
        // 정규화 금액 축을 행마다 덧붙인다. (each() 체인의 반환형은 Support\Collection 이다)
        $items = $data['items'] instanceof ResourceCollection
            ? $data['items']->collection
            : $data['items'];

        $data['items'] = collect($items)
            ->map(fn (ClaimItemResource $item) => $this->augmentItem($item->toArray($request), $type))
            ->values()
            ->all();

        return $data;
    }

    /**
     * 완료됐는데 환불신청서가 연결되지 않은 상품이 있는지 판정합니다. (교환·반품 — D9)
     *
     * 판정은 모델 `pendingRefundItems()` 한 곳이다 — 상세 배너·목록 뱃지와 같은 판정을 읽는다.
     *
     * @return bool 환불 미접수면 true
     */
    protected function hasPendingRefund(): bool
    {
        $claim = $this->resource;

        return ($claim instanceof OrderReturn || $claim instanceof OrderExchange)
            && $claim->pendingRefundItems()->isNotEmpty();
    }

    /**
     * 클레임 상세 화면 진입 경로를 반환합니다.
     *
     * 프론트 라우트(admin/ecommerce/claims/:claimNumber)의 앱 내 경로만 싣는다 —
     * origin 은 화면이 정한다. 번호가 없는 행(비정상 데이터)만 null 이다.
     *
     * @param  string|null  $claimNumber  클레임 번호
     * @return string|null 상세 경로 (번호가 없으면 null)
     */
    protected function resolveDetailUrl(?string $claimNumber): ?string
    {
        if ($claimNumber === null || $claimNumber === '') {
            return null;
        }

        return ClaimUrl::adminDetail($claimNumber);
    }

    /**
     * 상품 행에 정규화 금액 축을 덧붙입니다.
     *
     * 교환은 옵션 차액(`price_difference` — 신청 시점 동결값, 수량 반영 소계)이 그 상품의
     * 금액이고 부호가 청구/환불 방향을 정한다. 배송비는 신청서 단위 축이라 행에 안분하지
     * 않는다 — 신청서 레벨 `claim_shipping_fee`/`total_charge_amount` 가 담당한다.
     * 반품·취소는 대상 금액(단가×수량), 환불은 행 환불액이다.
     *
     * @param  array<string, mixed>  $row  ClaimItemResource 배열
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @return array<string, mixed> 정규화 금액이 덧붙은 행
     */
    protected function augmentItem(array $row, ClaimTypeEnum $type): array
    {
        $signed = match ($type) {
            ClaimTypeEnum::EXCHANGE => (float) ($row['price_difference'] ?? 0),
            ClaimTypeEnum::REFUND => (float) ($row['refund_amount'] ?? $row['subtotal_amount'] ?? 0),
            default => (float) ($row['subtotal_amount'] ?? 0),
        };

        // 방향은 `amount_type` 이 단독으로 나르고 `amount` 는 크기만 싣는다. 부호를 남기면
        // 같은 `refund` 라벨이 반품·취소에서는 양수, 저가 교환에서는 음수가 되어 화면이
        // 계약대로 유형 분기 없이 그릴 때 이중 부호(`-2,000원 환불`)가 된다.
        $magnitude = abs($signed);

        // 통화 자릿수는 주문 통화 스냅샷이 정한다 — raw 캐스팅으로 내보내면 0자리 통화에서
        // 같은 행의 형제 금액(`subtotal_amount` 등)은 정수인데 이 필드만 `3000.0` 이 된다.
        $row['amount'] = $this->roundToOrderCurrency($magnitude);
        $row['amount_formatted'] = $this->formatOrderCurrency($magnitude);
        $row['amount_type'] = $this->resolveAmountType($type, $signed);

        return $row;
    }

    /**
     * 정규화 금액의 방향(charge/refund/none)을 판정합니다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  float  $amount  부호가 살아 있는 원본 금액 (교환 차액은 음수일 수 있다)
     * @return string 금액 방향 (charge: 추가 청구, refund: 환불·대상 금액, none: 0원)
     */
    protected function resolveAmountType(ClaimTypeEnum $type, float $amount): string
    {
        if ($amount == 0.0) {
            return 'none';
        }

        if ($type === ClaimTypeEnum::EXCHANGE) {
            return $amount > 0 ? 'charge' : 'refund';
        }

        return 'refund';
    }
}
