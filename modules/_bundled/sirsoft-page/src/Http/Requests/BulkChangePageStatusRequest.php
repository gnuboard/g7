<?php

namespace Modules\Sirsoft\Page\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Page\Models\Page;

/**
 * 페이지 발행 상태 일괄 변경 요청
 */
class BulkChangePageStatusRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists(Page::class, 'id')],
            'published' => ['required', 'boolean'],
        ];
    }

    /**
     * 검증 오류 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-page::validation.ids.required'),
            'ids.array' => __('sirsoft-page::validation.ids.array'),
            'ids.min' => __('sirsoft-page::validation.ids.min'),
            'ids.*.integer' => __('sirsoft-page::validation.ids.integer'),
            'ids.*.exists' => __('sirsoft-page::validation.ids.exists'),
            'published.required' => __('sirsoft-page::validation.published.required'),
            'published.boolean' => __('sirsoft-page::validation.published.boolean'),
        ];
    }
}
