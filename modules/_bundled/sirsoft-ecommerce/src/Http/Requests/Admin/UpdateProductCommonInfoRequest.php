<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 공통정보 수정 요청
 */
class UpdateProductCommonInfoRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 요청 허용 여부(권한은 라우트 미들웨어가 검사)
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
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'content' => ['nullable', 'array', new TranslatableField(maxLength: 65535)],
            'content_mode' => 'nullable|in:text,html',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];

        // 훅을 통한 동적 규칙 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product-common-info.update_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sirsoft-ecommerce::validation.product_common_info.name_required'),
            'content_mode.in' => __('sirsoft-ecommerce::validation.product_common_info.content_mode_invalid'),
        ];
    }

    /**
     * 검증 오류 문구에 쓰는 필드 표시 이름
     *
     * 다국어 칸의 현재 언어 값을 비우면 「공통정보명의 한국어(ko) 값은 필수입니다.」처럼 칸 이름을 싣는다.
     *
     * @return array<string, string> 필드 키 → 표시 이름
     */
    public function attributes(): array
    {
        return [
            'name' => __('sirsoft-ecommerce::validation.attributes.common_info_name'),
        ];
    }
}
