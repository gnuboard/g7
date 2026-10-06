<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 현금영수증 발급액을 결제 통화로 환산할 수 없을 때 (B3)
 *
 * 발급 직전의 엄격 환산에서만 던진다 — 호출부(`CashReceiptService::issue()`)가 잡아 `CURRENCY_CONVERSION_FAILED` 실패 이력으로
 * 남긴다. 기준 통화 금액으로 그대로 발급하면 실제로 낸 금액과 다른 증빙이 신고된다.
 */
class CashReceiptConversionException extends RuntimeException {}
