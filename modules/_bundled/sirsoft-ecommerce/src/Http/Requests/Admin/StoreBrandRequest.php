<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Brand;

/**
 * 브랜드 생성 요청
 */
class StoreBrandRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'slug' => ['required', 'string', 'max:200', Rule::unique(Brand::class, 'slug'), 'regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/'],
            'website' => 'nullable|string|max:500|url',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ];

        // 훅을 통한 동적 규칙 확장
        return HookManager::applyFilters('sirsoft-ecommerce.brand.create_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sirsoft-ecommerce::validation.brand.name_required'),
            'slug.required' => __('sirsoft-ecommerce::validation.brand.slug_required'),
            'slug.unique' => __('sirsoft-ecommerce::validation.brand.slug_unique'),
            'slug.regex' => __('sirsoft-ecommerce::validation.brand.slug_format'),
            'website.url' => __('sirsoft-ecommerce::validation.brand.website_invalid_url'),
        ];
    }
}
