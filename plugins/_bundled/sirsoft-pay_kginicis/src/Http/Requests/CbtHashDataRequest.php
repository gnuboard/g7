<?php

// audit:allow api-doc-coverage reason: docblock 서술 정정만 수행 — 검증 규칙/요청·응답 계약 무변경

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\PayKginicis\Support\PaymentLimits;

/**
 * 해외결제(CBT/일본) 서명 해시 생성 요청 검증
 *
 * 국내 짝(SignatureRequest)과 동일 강도 + timestamp/checkout_token.
 *
 * 계약 변경: 종전에는 checkout_token 이 비어 있으면 토큰 검증 단계에서 403 이었으나,
 * 필수 검증으로 승격되어 422 가 된다. CBT 클라이언트(requestPayment.ts)는
 * `G7Core.api.post` 경유라 403/422 모두 실패 분기로 동일 취급되어 안전(실측 —
 * raw fetch `.ok` 검사는 nicepayments signData 쪽 클라이언트의 방식).
 */
class CbtHashDataRequest extends FormRequest
{
    /**
     * 요청 권한 — 공개 결제 표면이므로 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 서명 해시 생성 요청 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'oid' => ['required', 'string', 'max:40'],
            'price' => ['required', 'integer', 'min:'.PaymentLimits::MIN_PRICE],
            'timestamp' => ['required', 'string', 'max:20'],
            'checkout_token' => ['required', 'string', 'max:1024'],
            'buyer_email' => ['nullable', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
