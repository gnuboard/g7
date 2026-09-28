<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 클레임 완료 처리 옵션 DTO (D26)
 *
 * 반품완료·교환완료 전환은 **모달을 거쳐** 운영자가 두 가지를 고른 결과를 받는다:
 * 신청 상품 건별 재고 복구 여부와, 환불신청서를 지금 접수할지 여부.
 *
 * 두 값 모두 **필수 인자**다. 기본값을 두면 "요청에 없으면 이렇게" 라는 숨은 자동 실행이
 * 되살아나고, 그것이 D26 이 폐기한 동작이다. `claim.complete_defaults.*` 설정은 모달의
 * **초기 선택값**을 화면에 내려주는 용도이며 서비스는 그 설정을 읽지 않는다 — 설정을 바꿔도
 * 같은 요청은 같은 결과를 내야 한다.
 *
 * 재고 복구 키는 **클레임 신청 상품 행 ID**(`ecommerce_order_return_options.id` /
 * `ecommerce_order_exchange_options.id`)다. 운영자가 모달에서 체크하는 단위가 신청 상품 행이고
 * (D24 한 신청서에 상품이 여러 건), 한 주문옵션이 여러 신청서에 나뉘어 있을 수 있어 주문옵션
 * ID 로는 어느 신청 건의 선택인지 구분되지 않는다.
 *
 * 선교환의 「미회수로 완료」(D3)는 세 번째 선택이다 — 행 ID => 사유. 기본값은 빈 목록이다
 * (고르지 않으면 아무 행도 미회수로 끝나지 않는다 — 숨은 자동 실행이 아니다).
 */
final class ClaimCompletionOptions
{
    /**
     * @param  array<int, bool>  $restoreStockByItem  신청 상품 행 ID => 재고 복구 여부
     * @param  bool  $createRefundRequest  환불신청서를 지금 접수할지 여부
     * @param  array<int, string>  $unreturned  미회수로 완료할 신청 상품 행 ID => 사유 (선교환 D3)
     */
    public function __construct(
        public readonly array $restoreStockByItem,
        public readonly bool $createRefundRequest,
        public readonly array $unreturned = [],
    ) {}

    /**
     * 그 행을 미회수로 완료하라는 지시가 있는지 반환합니다.
     *
     * @param  int  $itemId  신청 상품 행 ID
     * @return bool 미회수 완료 지시가 있으면 true
     */
    public function isUnreturned(int $itemId): bool
    {
        return array_key_exists($itemId, $this->unreturned);
    }

    /**
     * 그 행의 미회수 사유를 반환합니다. (앞뒤 공백 제거)
     *
     * @param  int  $itemId  신청 상품 행 ID
     * @return string 사유 (지시가 없으면 빈 문자열)
     */
    public function unreturnedReason(int $itemId): string
    {
        return trim((string) ($this->unreturned[$itemId] ?? ''));
    }

    /**
     * 해당 신청 상품의 재고를 복구할지 반환합니다.
     *
     * 목록에 없는 항목은 **선택하지 않은 것**으로 본다 — 누락을 "기본값대로 복구" 로
     * 해석하면 운영자가 고르지 않은 재고가 판매 가능 수량에 들어간다.
     *
     * @param  int  $itemId  신청 상품 행 ID
     * @return bool 재고 복구 여부
     */
    public function shouldRestoreStock(int $itemId): bool
    {
        return (bool) ($this->restoreStockByItem[$itemId] ?? false);
    }

    /**
     * 재고 복구 대상이 하나라도 있는지 반환합니다.
     *
     * 소비처는 **완료 미리보기**(`POST admin/claims/completion-preview`, S6b 소유)다 — 모달이
     * 실행 **전에** "함께 실행되는 처리" 요약에 재고 복구 여부를 보여주려면 요청만 보고
     * 판정해야 하기 때문이다.
     *
     * **완료 실행 경로는 이 메서드를 쓰면 안 된다.** 묻는 질문이 다르다 — 이쪽은 "요청이
     * 복구를 하나라도 요구했는가"(요청 전체 기준)이고, 실행이 알아야 하는 것은 "이번
     * 라운드에 복구가 실제로 일어났는가"다. 셋이 갈린다: ⓐ 실행은 이번 라운드 대상 상품만
     * 순회하고 ⓑ 주문옵션이 실존할 때만 복구하며 ⓒ 완료가 분할되면 라운드마다 결과가
     * 다르다. 그래서 `OrderReturnService::completeReturn()` 은 지역 플래그로 실제 복구
     * 발생을 세어 헤더 `is_stock_restored` 를 세운다 — 이 메서드로 세우면 아무것도
     * 복구되지 않은 라운드가 복구됨으로 기록된다. (2026-08-22 확정 ⑥)
     *
     * @return bool 복구 대상이 있으면 true
     */
    public function hasStockRestoreTarget(): bool
    {
        foreach ($this->restoreStockByItem as $restore) {
            if ($restore) {
                return true;
            }
        }

        return false;
    }
}
