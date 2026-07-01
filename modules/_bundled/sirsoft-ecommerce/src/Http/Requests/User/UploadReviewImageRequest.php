<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return bool 항상 true (권한은 미들웨어에서 검증)
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
        // 리뷰 이미지 최대 용량 설정(MB)을 KB 로 환산 (설정 미반영 시 10MB 폴백)
        $maxKb = $this->maxImageSizeMb() * 1024;

        return [
            'image' => [
                'required',
                'file',
                'image',
                'max:'.$maxKb,
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
            'image.file' => __('sirsoft-ecommerce::validation.review_image.image_file'),
            'image.image' => __('sirsoft-ecommerce::validation.review_image.image_image'),
            'image.max' => __('sirsoft-ecommerce::validation.review_image.image_max', ['max' => $this->maxImageSizeMb()]),
        ];
    }

    /**
     * 리뷰 이미지 최대 용량(MB)을 설정에서 조회합니다.
     *
     * @return int 최대 용량(MB)
     */
    private function maxImageSizeMb(): int
    {
        return (int) module_setting(
            'sirsoft-ecommerce',
            'review_settings.max_image_size_mb',
            config('ecommerce.review.max_image_size_mb', 10)
        );
    }
}
