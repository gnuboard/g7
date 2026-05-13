<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 리뷰 이미지 업로드 요청
 */
class UploadReviewImageRequest extends FormRequest
{
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'image',
                'max:10240', // 10MB
            ],
        ];
    }

    /**
     * 검증 에러 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => __('sirsoft-ecommerce::validation.review_image.image_required'),
            'image.file'     => __('sirsoft-ecommerce::validation.review_image.image_file'),
            'image.image'    => __('sirsoft-ecommerce::validation.review_image.image_image'),
            'image.max'      => __('sirsoft-ecommerce::validation.review_image.image_max'),
        ];
    }
}
