<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;

/**
 * 활성/사용자 선택 가능 클레임 사유 목록 조회 요청
 *
 * 두 목록 모두 유형(type) 하나만 받으므로 같은 요청 객체를 공유한다.
 */
class ActiveClaimReasonRequest extends FormRequest
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
        ];
    }

    /**
     * 조회할 클레임 사유 유형을 반환합니다.
     *
     * 미지정 시 기본값은 환불(refund)이다.
     *
     * @return string 클레임 사유 유형
     */
    public function type(): string
    {
        return $this->validated()['type'] ?? ClaimReasonTypeEnum::REFUND->value;
    }
}
