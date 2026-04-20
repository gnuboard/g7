<?php

namespace App\Http\Requests\Plugin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 플러그인 업데이트 실행 요청 검증
 *
 * layout_strategy: 레이아웃 전략 (overwrite 또는 keep)
 * vendor_mode: Vendor 설치 모드 (auto, composer, bundled)
 */
class PerformPluginUpdateRequest extends FormRequest
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
            'layout_strategy' => ['nullable', 'string', 'in:overwrite,keep'],
            'vendor_mode' => ['nullable', 'string', 'in:auto,composer,bundled'],
        ];

        return HookManager::applyFilters('core.plugin.perform_update_rules', $rules);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'layout_strategy.in' => __('plugins.errors.invalid_layout_strategy'),
            'vendor_mode.in' => __('plugins.errors.invalid_vendor_mode'),
        ];
    }
}
