<?php

namespace Modules\Sirsoft\Page\Http\Requests;

use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 페이지 수정 요청
 */
class UpdatePageRequest extends FormRequest
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
            // 수정 시 슬러그는 변경 불가 (읽기 전용이므로 검증에서 제외)
            'title' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 255)],
            'content' => ['nullable', new TranslatableField(maxLength: 16777215)],
            'content_mode' => ['nullable', 'string', 'in:html,text'],
            'published' => ['nullable', 'boolean'],
            'seo_meta' => ['nullable', 'array'],
            'seo_meta.title' => ['nullable', 'string', 'max:255'],
            'seo_meta.description' => ['nullable', 'string', 'max:500'],
            'seo_meta.keywords' => ['nullable', 'string', 'max:500'],
            'temp_key' => ['nullable', 'string', 'max:64'],
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
            'title.required' => __('sirsoft-page::validation.title.required'),
            'content_mode.in' => __('sirsoft-page::validation.content_mode.in'),
            'published.boolean' => __('sirsoft-page::validation.published.boolean'),
        ];
    }
}
