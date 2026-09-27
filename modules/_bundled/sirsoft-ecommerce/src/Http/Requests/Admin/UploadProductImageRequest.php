<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Sirsoft\Ecommerce\Enums\ProductImageCollection;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesTempKey;

/**
 * 상품 이미지 업로드 요청
 */
class UploadProductImageRequest extends FormRequest
{
    use ValidatesTempKey;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool
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
                'mimes:jpeg,png,jpg,gif,webp',
                'max:10240', // 10MB
            ],
            'temp_key' => $this->tempKeyRules(),
            'collection' => [
                'nullable',
                'string',
                new Enum(ProductImageCollection::class),
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
            'sirsoft-ecommerce.product-image.filter_upload_validation_rules',
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
            'file.required' => __('sirsoft-ecommerce::validation.product_images.file.required'),
            'file.file' => __('sirsoft-ecommerce::validation.product_images.file.file'),
            'file.image' => __('sirsoft-ecommerce::validation.product_images.file.image'),
            'file.mimes' => __('sirsoft-ecommerce::validation.product_images.file.mimes'),
            'file.max' => __('sirsoft-ecommerce::validation.product_images.file.max'),
            'temp_key.string' => __('sirsoft-ecommerce::validation.product_images.temp_key.string'),
            'collection.Illuminate\Validation\Rules\Enum' => __('sirsoft-ecommerce::validation.product_images.collection.enum'),
            'alt_text.array' => __('sirsoft-ecommerce::validation.product_images.alt_text.array'),
        ];
    }
}
