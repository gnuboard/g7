<?php

namespace Modules\Sirsoft\Page\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 페이지 발행 상태 변경 요청 (단일)
 */
class ChangePageStatusRequest extends FormRequest
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
            'published.required' => __('sirsoft-page::validation.published.required'),
            'published.boolean' => __('sirsoft-page::validation.published.boolean'),
        ];
    }
}
