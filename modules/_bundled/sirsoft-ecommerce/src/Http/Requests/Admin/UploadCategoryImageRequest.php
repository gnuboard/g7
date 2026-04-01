<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 카테고리 이미지 업로드 요청
 */
class UploadCategoryImageRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('sirsoft-ecommerce.categories.update');
    }

    /**
     * 요청에 적용할 검증 규칙을 반환합니다.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
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
            'temp_key' => [
                'nullable',
                'string',
                'max:64',
            ],
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
            'temp_key.max' => __('sirsoft-ecommerce::validation.category_images.temp_key.max'),
            'collection.in' => __('sirsoft-ecommerce::validation.category_images.collection.in'),
            'alt_text.array' => __('sirsoft-ecommerce::validation.category_images.alt_text.array'),
        ];
    }
}