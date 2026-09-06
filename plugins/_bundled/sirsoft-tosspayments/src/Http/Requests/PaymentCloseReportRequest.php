<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentCloseReportRequest extends FormRequest
{
    /**
     * 결제창 닫힘 보고 요청을 허용합니다.
     *
     * 인증은 요구하지 않는다 — 결제창 컨텍스트에서 호출되기 때문이다. 대신 컨트롤러가
     * 구매자 정보·금액 대조로 자격을 검증한다.
     *
     * @return bool 요청 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 결제창 닫힘 보고 요청 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'orderId' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'integer', 'min:1'],
            'buyer_email' => ['nullable', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
            'reason' => ['nullable', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:60'],
        ];
    }
}
