<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 검색 프리셋 목록 조회 요청
 */
class SearchPresetListRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
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
            'target_screen' => 'nullable|string|in:products,orders,coupons',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.search_preset.list_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'target_screen.in' => __('sirsoft-ecommerce::validation.search_preset.target_screen_in'),
        ];
    }
}
