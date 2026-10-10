<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 로그인 비밀번호 변경 단계 '다음에 변경' 요청
 *
 * '다음에 변경 허용' 모드에서 만료된 회원이 변경을 미루고 로그인을 마친다. 아직 로그인 전이라 인증
 * 미들웨어를 거치지 않으며, 주체는 변경 단계 식별자가 가리키는 회원이다.
 *
 * @since 7.1.0
 */
class PostponePasswordChangeRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (주체 식별은 변경 단계 식별자가 담당)
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

        return HookManager::applyFilters('core.auth.password_change_postpone_validation_rules', $rules, $this);
    }

    /**
     * 검증 실패 메시지를 반환합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return [
            'challenge_id.required' => __('validation.auth.password_change.challenge_required'),
            'challenge_id.uuid' => __('validation.auth.password_change.challenge_invalid'),
        ];
    }

    /**
     * 요청이 들어온 로그인 경로를 판정합니다 (관리자 경로 라우트 이름은 `api.auth.admin.` 로 시작).
     *
     * @return string admin | user
     */
    public function loginGuard(): string
    {
        $name = (string) $this->route()?->getName();

        return str_starts_with($name, 'api.auth.admin.') ? 'admin' : 'user';
    }
}
