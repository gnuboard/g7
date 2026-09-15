<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 2단계 인증 코드 재발송 요청
 *
 * 비밀번호 확인 단계가 돌려준 challenge 로만 재발송할 수 있습니다. 이 요청은 아직 로그인 전
 * 상태에서 호출되므로 인증 미들웨어를 거치지 않으며, 주체 식별은 challenge 에 기록된
 * 사용자로만 이루어집니다.
 */
class TwoFactorResendRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (주체 식별은 challenge 가 담당)
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
            'challenge_id' => ['required', 'string', 'uuid'],
        ];

        // 확장이 자체 2단계 수단을 붙일 때 재발송 입력을 추가할 수 있도록 개방한다
        return HookManager::applyFilters('core.auth.two_factor_resend_validation_rules', $rules, $this);
    }

    /**
     * 검증 실패 메시지를 반환합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return [
            'challenge_id.required' => __('validation.auth.two_factor.challenge_required'),
            'challenge_id.uuid' => __('validation.auth.two_factor.challenge_invalid'),
        ];
    }
}
