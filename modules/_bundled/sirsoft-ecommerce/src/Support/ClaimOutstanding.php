<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 클레임 미수납 청구액 산식 (단일 출처)
 *
 * 미수납 = max(0, 청구액 − 실수납 합계) 이고, 0 원 미만은 없다(초과 수납은 차액 정산이 환급으로
 * 해소한다). 이 식이 두 자리에 있었다 — 결제 서비스(`ClaimPaymentService::outstandingAmount()`, 단건 ·
 * 저장소 합산)와 관리자 목록(`ClaimAdminListResource::outstandingFromLoadedPayments()`, 행마다 쿼리를
 * 치지 않도록 eager load 한 결제 건 합산). 재료를 모으는 방식만 다르고 식은 같아야 하므로 식을 이 한
 * 곳에 둔다 — 한쪽 사본만 반올림·클램프가 바뀌면 목록과 상세가 같은 신청서에 다른 미수납을 적는다.
 */
final class ClaimOutstanding
{
    /**
     * 미수납 청구액을 계산합니다.
     *
     * @param  float  $totalCharge  청구액 (`total_charge_amount`)
     * @param  float  $paid  실수납 합계 (부분 환급을 뺀 값)
     * @return float 미수납 청구액 (0 이상, 소수 둘째 자리 반올림)
     */
    public static function amount(float $totalCharge, float $paid): float
    {
        return max(0.0, round($totalCharge - $paid, 2));
    }
}
