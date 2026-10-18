<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentCallback;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 추가결제 콜백의 실패 코드 변환 단위 테스트
 *
 * `errorCode()` 가 만든 값은 그대로 신청서 상세 주소의 `?error=` 가 되고, 유저 템플릿이
 * 「실패 코드 {code}」 배너로 그린다. 즉 이 메서드가 구매자가 보는 유일한 실패 식별자를
 * 정한다 — 원문 메시지는 의도적으로 싣지 않기 때문이다.
 *
 * **계약: 원문 메시지는 싣지 않되 PG 가 준 코드는 싣는다.**
 * `ClaimPaymentCallback` 헤더와 `ClaimPaymentService::approvePgPayment()` 둘 다 이 문장을
 * 적어 두었다. 코드가 실리지 않으면 모든 승인 거절이 같은 토큰으로 수렴해, 구매자는 사유를
 * 모르고 고객센터는 건을 특정할 식별자를 못 받는다.
 *
 * 회귀 배경 (#599 3차 실측 F-5, 2026-09-22): 토스 Confirm 이 `PG_API_ERROR` 로 거절하고
 * 결제행에 사유가 정상 기록됐는데도 화면 배너는 「실패 코드 **failed**」였다. `errorCode()`
 * 가 예외 **키**의 마지막 조각만 보았고, `OrderClaimException::paymentFailed()` 의 키 조각은
 * 상수 `failed` 이며 진짜 코드는 치환 파라미터에 따로 실려 있었기 때문이다.
 */
class ClaimPaymentCallbackTest extends ModuleTestCase
{
    /**
     * PG 가 준 코드가 화면 쿼리까지 살아서 도착하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario reason_origin=pg_parameter,value_shape=code_shaped
     *
     * @effects claim_payment_error_code_prefers_exception_parameter
     */
    public function test_pg_승인_거절_코드가_쿼리_코드로_그대로_나온다(): void
    {
        $e = OrderClaimException::paymentFailed('PG_API_ERROR');

        $this->assertSame(
            'PG_API_ERROR',
            ClaimPaymentCallback::errorCode($e),
            'PG 가 준 코드가 버려지고 일반 토큰으로 뭉개졌다 — 구매자 화면이 사유를 잃는다',
        );
    }

    /**
     * 파라미터가 없는 예외는 기존대로 키 마지막 조각을 쓰는지 검증합니다.
     *
     * 금액 불일치는 PG 코드가 아니라 **모듈이 내린 판정**이라 파라미터가 없다. 이 경로가
     * `amount_mismatch` 를 잃으면 망취소 보상 분기(`str_ends_with(..., 'amount_mismatch')`)와
     * 화면 안내가 동시에 낡는다.
     *
     * @return void 반환값 없음
     *
     * @scenario reason_origin=module_verdict,value_shape=code_shaped
     *
     * @effects claim_payment_error_code_falls_back_to_key_fragment
     */
    public function test_파라미터가_없으면_예외_키의_마지막_조각을_쓴다(): void
    {
        $this->assertSame(
            'amount_mismatch',
            ClaimPaymentCallback::errorCode(OrderClaimException::paymentAmountMismatch()),
        );

        $this->assertSame(
            'not_pending',
            ClaimPaymentCallback::errorCode(OrderClaimException::paymentNotPending()),
        );
    }

    /**
     * 코드 자리에 올 수 없는 값은 화면으로 흘리지 않는지 검증합니다.
     *
     * 이 값은 구매자 화면에 그대로 그려지므로, PG 가 코드 자리에 문장·태그·줄바꿈을 담아
     * 보내면 그것이 배너 문구가 된다. 원문 메시지를 싣지 않기로 한 이유가 그대로 여기에도
     * 적용된다 — 코드로 보이지 않는 값은 받지 않고 일반 토큰으로 닫는다.
     *
     * @return void 반환값 없음
     *
     * @scenario reason_origin=pg_parameter,value_shape=free_text
     *
     * @effects claim_payment_error_code_rejects_non_code_shaped_values
     */
    public function test_코드_모양이_아닌_값은_일반_토큰으로_닫는다(): void
    {
        $this->assertSame(
            'failed',
            ClaimPaymentCallback::errorCode(
                OrderClaimException::paymentFailed('카드사에서 <b>결제</b>를 거절했어요.'),
            ),
            '코드 자리에 온 문장이 그대로 구매자 배너 문구가 된다',
        );

        $this->assertSame(
            'failed',
            ClaimPaymentCallback::errorCode(OrderClaimException::paymentFailed('')),
        );
    }
}
