<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentCloseReportRequest extends FormRequest
{
    /**
     * 결제창 닫힘 보고 요청을 허용합니다.
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
            'oid'            => ['required', 'string', 'max:40'],
            'price'          => ['required', 'integer', 'min:1'],
            'buyer_email'    => ['nullable', 'string', 'max:255'],
            'buyer_phone'    => ['nullable', 'string', 'max:30'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'reason'         => ['nullable', 'string', 'max:80'],
        ];
    }
}
