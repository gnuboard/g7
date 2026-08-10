<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;

/**
 * 클레임 사유 목록 조회 요청
 *
 * 목록 필터(유형·활성 여부·귀책 구분·검색어)를 검증한다.
 */
class IndexClaimReasonRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 true (권한은 라우트 permission 미들웨어가 담당)
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
            'type' => ['sometimes', 'string', Rule::in(ClaimReasonTypeEnum::values())],
            'fault_type' => ['sometimes', 'string', Rule::in(ClaimReasonFaultTypeEnum::values())],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 검증할 필드의 이름을 커스터마이징
     *
     * @return array<string, string> 필드명 → 표시명 매핑
     */
    public function attributes(): array
    {
        return [
            'type' => __('sirsoft-ecommerce::validation.attributes.claim_reason_type'),
            'fault_type' => __('sirsoft-ecommerce::validation.attributes.claim_reason_fault_type'),
            'is_active' => __('sirsoft-ecommerce::validation.attributes.claim_reason_is_active'),
            'search' => __('sirsoft-ecommerce::validation.attributes.claim_reason_search'),
        ];
    }

    /**
     * 검증된 필터만 추린 배열을 반환합니다.
     *
     * 컨트롤러가 `only()` 로 요청을 직접 훑지 않도록, 필터 해석 책임을 요청 객체가 갖는다.
     * 유형 미지정 시 기본값은 환불(refund)이다.
     *
     * @return array<string, mixed> 서비스에 넘길 필터 배열
     */
    public function filters(): array
    {
        $filters = $this->validated();
        $filters['type'] ??= ClaimReasonTypeEnum::REFUND->value;

        return $filters;
    }
}
