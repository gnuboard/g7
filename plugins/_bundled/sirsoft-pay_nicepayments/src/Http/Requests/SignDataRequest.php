<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 결제 요청 SignData 발급 요청 검증
 *
 * 비회원 결제도 호출하는 공개 표면 — 형식·길이만 닫고, 주문·금액·구매자 대조는
 * 컨트롤러의 도메인 검증(우리 DB 기준)이 담당한다.
 *
 * 계약 변경: 종전 수동 검사(400) → 표준 422. 클라이언트는 `.ok` 만 검사하므로 안전(실측).
 * 상한 근거: Amt 12자리(나이스페이먼츠 스펙), Moid 64자.
 */
class SignDataRequest extends FormRequest
{
    /**
     * 요청 권한 — 공개 엔드포인트이므로 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * SignData 발급 요청 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'amt' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'moid' => ['required', 'string', 'max:64'],
        ];
    }
}
