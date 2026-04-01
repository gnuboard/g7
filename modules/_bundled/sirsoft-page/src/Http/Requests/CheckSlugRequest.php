<?php

namespace Modules\Sirsoft\Page\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 슬러그 중복 확인 요청
 */
class CheckSlugRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'exclude_id' => ['nullable', 'integer', 'min:1'],
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
            'slug.required' => __('sirsoft-page::validation.slug.required'),
            'slug.max' => __('sirsoft-page::validation.slug.max'),
            'slug.regex' => __('sirsoft-page::validation.slug.format'),
        ];
    }
}
