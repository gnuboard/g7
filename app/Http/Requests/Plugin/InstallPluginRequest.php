<?php

namespace App\Http\Requests\Plugin;

use App\Extension\HookManager;
use App\Rules\ValidExtensionIdentifier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 플러그인 설치 요청 검증
 *
 * 플러그인 설치 시 필요한 데이터를 검증합니다.
 */
class InstallPluginRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행되므로
     * FormRequest 레벨은 항상 통과시킵니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'plugin_name' => ['required', 'string', 'max:255', new ValidExtensionIdentifier],
            'vendor_mode' => ['nullable', 'string', 'in:auto,composer,bundled'],
            // cascade 동반 설치 — install-preview 응답을 바탕으로 사용자가 선택한 항목
            'dependencies' => ['nullable', 'array'],
            'dependencies.*.type' => ['required_with:dependencies', 'string', 'in:module,plugin'],
            'dependencies.*.identifier' => ['required_with:dependencies', 'string', 'max:255'],
            'language_packs' => ['nullable', 'array'],
            'language_packs.*' => ['string', 'max:255'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.plugin.install_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plugin_name.required' => __('plugins.validation.plugin_name_required'),
            'plugin_name.string' => __('plugins.validation.plugin_name_string'),
            'plugin_name.max' => __('plugins.validation.plugin_name_max', ['max' => 255]),
        ];
    }
}
