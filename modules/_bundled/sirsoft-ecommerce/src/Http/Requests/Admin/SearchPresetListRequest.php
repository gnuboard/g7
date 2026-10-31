<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\SearchPresetTargetScreen;

/**
 * 검색 프리셋 목록 조회 요청
 */
class SearchPresetListRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool
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
            // 허용 어휘의 단일 출처는 Enum 이다 — 생성(StoreSearchPresetRequest)이 같은 Enum 을
            // 게이트하므로, 목록만 리터럴을 들고 있으면 저장할 수 있는 화면과 조회할 수 있는
            // 화면이 갈린다(종전 리터럴은 Enum 에 없는 coupons 를 허용하고 customers 를 거절했다
            // — coupons 는 생성 게이트가 막아 저장된 행이 존재할 수 없는 죽은 어휘였다).
            'target_screen' => ['nullable', 'string', Rule::in(SearchPresetTargetScreen::values())],
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
