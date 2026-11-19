<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 로그인 인계 코드 교환 요청
 *
 * 외부 인증(소셜 로그인 등)이 본인 확인을 마친 회원에 대해 발급한 일회용 인계 코드를 로그인 화면이 교환한다.
 * 아직 로그인 전 상태에서 호출되므로 인증 미들웨어를 거치지 않으며, 주체는 코드가 식별한다.
 *
 * @since 7.2.0
 */
class LoginHandoffRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (주체 식별은 인계 코드가 담당)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:128'],
        ];

        return HookManager::applyFilters('core.auth.login_handoff_validation_rules', $rules, $this);
    }

    /**
     * 검증 실패 메시지를 반환합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return [
            'code.required' => __('validation.auth.login_handoff.code_required'),
            'code.string' => __('auth.login_handoff_invalid'),
            'code.max' => __('auth.login_handoff_invalid'),
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 라벨
     */
    public function attributes(): array
    {
        return [
            'code' => __('validation.attributes.login_handoff_code'),
        ];
    }
}
