<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 설정 복원 요청 FormRequest
 *
 * 백업에서 설정을 복원할 때 백업 경로를 검증합니다.
 * 권한은 라우트의 permission 미들웨어(core.settings.update)가 담당합니다.
 */
class RestoreSettingsRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * 권한 검사는 permission 미들웨어 체인에 위임하므로 항상 true 를 반환합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'backup_path' => 'required|string',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.settings.restore_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'backup_path.required' => __('settings.backup_path_required'),
        ];
    }
}
