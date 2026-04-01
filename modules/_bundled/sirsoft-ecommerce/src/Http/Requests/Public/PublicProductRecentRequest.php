<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 최근 본 상품 목록 조회 요청 (공개)
 *
 * 최근 본 상품 ID 목록 파라미터를 검증합니다.
 */
class PublicProductRecentRequest extends FormRequest
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
            'ids' => ['nullable', 'string', 'max:500'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.public_recent_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.string' => __('sirsoft-ecommerce::validation.public_product.ids.string'),
            'ids.max' => __('sirsoft-ecommerce::validation.public_product.ids.max'),
        ];
    }
}
