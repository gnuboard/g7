<?php

namespace Plugins\Sirsoft\HeroSection\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\HeroSection\Models\Banner;

/**
 * 일괄 영구 삭제 요청.
 */
class BannerBulkDeleteRequest extends FormRequest
{
    /**
     * 권한은 라우트 permission 미들웨어가 판정합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', Rule::exists(Banner::class, 'id')],
        ];
    }

    /**
     * 검증 오류에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 이름
     */
    public function attributes(): array
    {
        return [
            'ids' => __('sirsoft-hero_section::validation.attributes.ids'),
            'ids.*' => __('sirsoft-hero_section::validation.attributes.ids_item'),
        ];
    }
}
