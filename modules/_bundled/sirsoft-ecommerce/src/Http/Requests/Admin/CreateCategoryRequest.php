<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Category;

/**
 * 카테고리 생성 요청
 */
class CreateCategoryRequest extends FormRequest
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
     * 검증 전 데이터 전처리
     *
     * 프론트엔드에서 문자열 "true"/"false"로 전송되는 boolean 값을 변환합니다.
     */
    protected function prepareForValidation(): void
    {
        $isActive = $this->input('is_active');

        if ($isActive === 'true' || $isActive === '1') {
            $this->merge(['is_active' => true]);
        } elseif ($isActive === 'false' || $isActive === '0') {
            $this->merge(['is_active' => false]);
        }
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
            'description' => ['nullable', 'array', new TranslatableField()],
            'parent_id' => ['nullable', Rule::exists(Category::class, 'id')],
            'slug' => [
                'required',
                'string',
                'max:200',
                Rule::unique(Category::class, 'slug'),
                'regex:/^[a-z][a-z0-9-]*$/', // 영문 소문자로 시작, 영문/숫자/하이픈만 허용
            ],
            'is_active' => 'boolean',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string',
            'temp_key' => 'nullable|string|max:64', // FileUploader temp_key
        ];

        // 훅을 통한 동적 규칙 확장
        return HookManager::applyFilters('sirsoft-ecommerce.category.create_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sirsoft-ecommerce::validation.category.name_required'),
            'slug.required' => __('sirsoft-ecommerce::validation.category.slug_required'),
            'slug.unique' => __('sirsoft-ecommerce::validation.category.slug_unique'),
            'slug.regex' => __('sirsoft-ecommerce::validation.category.slug_format'),
            'parent_id.exists' => __('sirsoft-ecommerce::validation.category.parent_not_found'),
        ];
    }
}
