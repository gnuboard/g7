<?php

namespace App\Http\Requests\Admin\Extension;

use App\Extension\HookManager;
use App\Rules\SafeCustomAssetPath;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 사용자 추가 에셋 **이름변경(이동)** 요청 검증
 *
 * 권한 검사는 라우트의 permission 미들웨어(`core.extensions.custom_assets.manage`)가 담당한다.
 *
 * 두 경로 모두 {@see SafeCustomAssetPath} 를 지난다 — 한쪽만 검사하면 안전한 원본을
 * `../` 대상으로 옮기는 경로가 열린다. 경로 판정 로직을 새로 만들지 않고 기존 규칙을 그대로
 * 쓰는 이유는, 판정이 두 벌이 되는 순간 한쪽만 고쳐진 상태가 조용한 우회로가 되기 때문이다.
 */
class RenameExtensionCustomAssetRequest extends FormRequest
{
    /**
     * 요청 권한 확인 — 권한은 permission 미들웨어가 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 전처리 — 역슬래시를 슬래시로 맞춘다 (Windows 에서 붙여넣은 경로).
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        foreach (['from', 'to'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => trim(str_replace('\\', '/', $value))]);
            }
        }
    }

    /**
     * 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'from' => ['required', 'string', 'max:255', new SafeCustomAssetPath],
            'to' => ['required', 'string', 'max:255', 'different:from', new SafeCustomAssetPath],
        ];

        return HookManager::applyFilters('core.extension_custom_asset.rename_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required' => __('custom_assets.validation.path_required'),
            'to.required' => __('custom_assets.validation.path_required'),
            'to.different' => __('custom_assets.validation.rename_same_path'),
        ];
    }
}
