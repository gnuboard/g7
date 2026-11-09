<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;

/**
 * 이커머스 본문 미디어 업로드 계약 조회 요청 (#883 4단계)
 */
class ContentMediaTargetRequest extends FormRequest
{
    /**
     * 요청 권한 확인
     *
     * @return bool 항상 true (권한은 미들웨어·컨트롤러에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'owner_type' => ['required', 'string', Rule::in(ContentMediaOwnerType::values())],
        ];
    }

    /**
     * 검증 속성 표시명
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'owner_type' => __('sirsoft-ecommerce::validation.attributes.content_media.owner_type'),
        ];
    }
}
