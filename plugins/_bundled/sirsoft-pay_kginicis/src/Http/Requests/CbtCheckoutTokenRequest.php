<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\PayKginicis\Support\PaymentLimits;

/**
 * 해외결제(CBT/일본) 체크아웃 토큰 발급 요청 검증
 *
 * 국내 짝(SignatureRequest)과 동일 강도 — 종전에는 CBT 경로만 raw 입력이라
 * 서명·토큰 발급 계약이 국내와 3단계 드리프트 상태였다.
 * 주문·금액·구매자 대조는 컨트롤러의 도메인 검증(우리 DB 기준)이 담당한다.
 */
class CbtCheckoutTokenRequest extends FormRequest
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
     * 체크아웃 토큰 발급 요청 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'oid' => ['required', 'string', 'max:40'],
            // 하한은 국내와 동일하게 PaymentLimits SSoT (JPY 도 1 단위 결제 성립)
            'price' => ['required', 'integer', 'min:'.PaymentLimits::MIN_PRICE],
            'buyer_email' => ['nullable', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
