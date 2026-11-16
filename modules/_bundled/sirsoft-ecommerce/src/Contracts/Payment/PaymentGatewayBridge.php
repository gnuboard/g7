<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment;

/**
 * 결제사 플러그인이 쓰는 이커머스 결제 공개 계약 묶음
 *
 * 결제사 플러그인은 이커머스 Model, Service, Repository, 테이블을 직접 쓰지 않고 이 계약만 쓴다.
 * 서비스 컨테이너로 주입받는다(`app(PaymentGatewayBridge::class)` 또는 하위 인터페이스).
 *
 * @since 1.4.0
 */
interface PaymentGatewayBridge extends ClaimPaymentLookup, OrderAccess, PaymentLifecycle, PaymentLookup
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;
}
