<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentCloseReportRequest extends FormRequest
{
    /**
     * 결제창 닫힘 보고 요청을 허용합니다.
     *
     * 로그인을 강제하지 않는다 — 비회원도 결제창을 닫을 수 있기 때문이다. 대신 컨트롤러가
     * 주문 소유자(회원 본인 로그인 / 비회원 조회 토큰)와 금액을 확인한다. 주문자 이메일·전화번호는
     * 제3자도 알 수 있어 소유 증명이 아니므로 받지 않는다.
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
            'reason' => ['nullable', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:60'],
        ];
    }
}
