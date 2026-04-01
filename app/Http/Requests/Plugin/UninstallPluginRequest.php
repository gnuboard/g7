<?php

namespace App\Http\Requests\Plugin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 플러그인 제거 요청 검증
 *
 * 플러그인 제거 시 필요한 데이터를 검증합니다.
 */
class UninstallPluginRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
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
            'plugin_name' => ['required', 'string', 'max:255'],
            'delete_data' => 'sometimes|boolean',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.plugin.uninstall_validation_rules', $rules, $this);
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
