<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment;

use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\SamplePaymentSpec;

/**
 * API 문서 실측용 샘플 결제 생성 공개 계약
 *
 * 문서 생성 도구는 샘플 주문번호로 실제 조회를 하므로 DB 행이 있어야 한다. 이커머스 팩토리를 쓰므로 개발 환경에서만 쓴다.
 *
 * @since 1.4.0
 */
interface PaymentSampleProvisioner
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;

    /**
     * 샘플 주문, 결제 행, 배송지를 없을 때만 만들고 주문번호를 돌려줍니다(멱등).
     *
     * @param  SamplePaymentSpec  $spec  샘플 명세
     * @return string|null 주문번호(소유 회원이 없으면 null)
     *
     * @throws \InvalidArgumentException 허용 목록 밖 결제 행 컬럼이 있을 때
     */
    public function ensureSamplePayment(SamplePaymentSpec $spec): ?string;
}
