<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;

/**
 * 클레임 추가결제 수단 검증 규칙 (`claim.charge_payment_methods` 허용 목록)
 *
 * 화면 옵션 ⊆ 게이트 ⊆ 서비스 — 허용 목록은 설정 단일 출처에서 읽고, 서비스도 같은 목록으로 재판정한다.
 */
trait ValidatesClaimPaymentMethod
{
    /**
     * 결제 수단 규칙을 반환합니다.
     *
     * @return array<int, mixed> 규칙
     */
    protected function paymentMethodRules(): array
    {
        $allowed = array_values(array_map('strval', (array) module_setting(
            'sirsoft-ecommerce',
            'claim.charge_payment_methods',
            [PaymentMethodEnum::CARD->value, PaymentMethodEnum::POINT->value],
        )));

        return ['nullable', 'string', Rule::in($allowed)];
    }
}
