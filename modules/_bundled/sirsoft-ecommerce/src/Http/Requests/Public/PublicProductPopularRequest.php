<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 인기 상품 목록 조회 요청 (공개)
 *
 * 인기 상품 조회 시 반환 개수 제한 파라미터를 검증합니다.
 */
class PublicProductPopularRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.public_popular_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'limit.integer' => __('sirsoft-ecommerce::validation.public_product.limit.integer'),
            'limit.min' => __('sirsoft-ecommerce::validation.public_product.limit.min'),
            'limit.max' => __('sirsoft-ecommerce::validation.public_product.limit.max'),
        ];
    }
}
