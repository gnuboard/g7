<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 고객 요청 대리 철회 요청 (S14)
 *
 * 철회할 수 있는 단계는 고객 철회와 같다 — 서비스가 판정한다. 사유는 필수다: 고객에게
 * 「고객님 요청으로 판매자가 철회했습니다」 가 나가므로 누구의 어떤 요청이었는지를 남긴다.
 */
class WithdrawClaimOnBehalfRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인 (권한 체크는 라우트의 permission 미들웨어에서 수행)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 메시지 배열
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('sirsoft-ecommerce::validation.claims.withdraw_reason.required'),
            'reason.max' => __('sirsoft-ecommerce::validation.claims.withdraw_reason.max'),
        ];
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시명
     *
     * @return array<string, string> 필드 키 → 표시명
     */
    public function attributes(): array
    {
        return [
            'reason' => __('sirsoft-ecommerce::validation.request_attributes.withdraw_reason'),
        ];
    }

    /**
     * 철회 사유를 반환합니다.
     *
     * @return string 철회 사유
     */
    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
