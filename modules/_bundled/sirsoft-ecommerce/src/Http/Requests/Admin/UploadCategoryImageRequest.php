<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesTempKey;

/**
 * 카테고리 이미지 업로드 요청
 */
class UploadCategoryImageRequest extends FormRequest
{
    use ValidatesTempKey;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * 권한(sirsoft-ecommerce.categories.update)은 두 업로드 라우트의 permission 미들웨어가 검사한다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙을 반환합니다.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'file' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,jpg,gif,svg,webp',
                'max:10240', // 10MB
            ],
            'temp_key' => $this->tempKeyRules(),
            'collection' => [
                'nullable',
                'string',
                'max:100',
            ],
            'alt_text' => [
                'nullable',
                'array',
            ],
            'alt_text.ko' => [
                'nullable',
                'string',
                'max:255',
            ],
            'alt_text.en' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];

        return HookManager::applyFilters(
            'sirsoft-ecommerce.category-image.filter_upload_validation_rules',
            $rules
        );
    }

    /**
     * 검증 에러 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('sirsoft-ecommerce::validation.category_images.file.required'),
            'file.file' => __('sirsoft-ecommerce::validation.category_images.file.file'),
            'file.image' => __('sirsoft-ecommerce::validation.category_images.file.image'),
            'file.mimes' => __('sirsoft-ecommerce::validation.category_images.file.mimes'),
            'file.max' => __('sirsoft-ecommerce::validation.category_images.file.max'),
            'temp_key.string' => __('sirsoft-ecommerce::validation.category_images.temp_key.string'),
            'collection.in' => __('sirsoft-ecommerce::validation.category_images.collection.in'),
            'alt_text.array' => __('sirsoft-ecommerce::validation.category_images.alt_text.array'),
        ];
    }
}
