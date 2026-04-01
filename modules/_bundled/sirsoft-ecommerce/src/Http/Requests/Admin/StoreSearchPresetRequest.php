<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\SearchPresetTargetScreen;
use Modules\Sirsoft\Ecommerce\Models\SearchPreset;

/**
 * 검색 프리셋 생성 요청
 */
class StoreSearchPresetRequest extends FormRequest
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
        $targetScreen = $this->input('target_screen', 'products');

        $rules = [
            'target_screen' => ['nullable', Rule::in(SearchPresetTargetScreen::values())],
            'name' => [
                'required',
                'string',
                'max:100',
                // 중복 이름 체크: 동일 사용자, 동일 화면에서 이름 중복 방지
                Rule::unique(SearchPreset::class, 'preset_name')
                    ->where('user_id', Auth::id())
                    ->where('target_screen', $targetScreen),
            ],
            'conditions' => ['required', 'array'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.preset.store_validation_rules', $rules, $this);
    }

    /**
     * 유효성 검사 메시지
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sirsoft-ecommerce::validation.preset.name_required'),
            'name.unique' => __('sirsoft-ecommerce::validation.preset.name_exists'),
            'conditions.required' => __('sirsoft-ecommerce::validation.preset.conditions_required'),
        ];
    }
}
