<?php

namespace App\Http\Requests\Admin\Identity;

use App\Models\IdentityMessageTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * IDV 메시지 템플릿 미리보기 FormRequest.
 *
 * 변수 치환 결과(subject/body)를 즉시 렌더링해 반환할 때 사용합니다.
 */
class PreviewIdentityMessageTemplateRequest extends FormRequest
{
    /**
     * 권한 확인 (미들웨어에서 처리).
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'template_id' => ['required', 'integer', Rule::exists(IdentityMessageTemplate::class, 'id')],
            'data' => ['sometimes', 'array'],
            'data.*' => ['nullable'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }
}
