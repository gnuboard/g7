<?php

namespace App\Http\Requests\Auth;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Http\Requests\Concerns\ChecksPasswordReuse;
use App\Models\User;
use App\Rules\PasswordPolicy;
use App\Services\PasswordChangeChallengeService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 로그인 비밀번호 변경 단계 완료 요청
 *
 * 비밀번호·2단계 인증을 통과했지만 비밀번호를 바꿔야 하는 회원이, 로그인이 돌려준 변경 단계 식별자와
 * 새 비밀번호를 보낸다. 아직 로그인 전이라 인증 미들웨어를 거치지 않으며, 주체는 식별자가 가리키는
 * 회원이다. 재사용 금지·현재값 비교는 식별자가 유효할 때만 평가한다(추측 오라클 차단).
 *
 * @since 7.1.0
 */
class CompletePasswordChangeRequest extends FormRequest
{
    use ChecksPasswordReuse;

    /**
     * 식별자가 가리키는 회원 (요청당 1회 해석)
     */
    private ?User $challengeUser = null;

    /**
     * 식별자 해석 여부
     */
    private bool $challengeResolved = false;

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
        // 확인 불일치는 확인 칸(`password_confirmation`)에 붙인다 — 화면이 확인 칸 아래에 띄운다(2026-10-04 결정).
        // `confirmed` 를 새 비밀번호 칸에 두면 오류가 새 비밀번호 칸으로 간다.
        $rules = [
            'challenge_id' => ['required', 'string', 'uuid'],
            'password' => ['required', 'string', ...PasswordPolicy::rules($this->challengeUser()?->email)],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ];

        // 확장이 새 비밀번호 규칙을 덧붙일 수 있도록 개방한다
        return HookManager::applyFilters('core.auth.password_change_validation_rules', $rules, $this);
    }

    /**
     * 재사용 금지·현재값 비교를 변경 단계 식별자 확인 **뒤에만** 붙입니다.
     *
     * 식별자가 무효면 회원 의존 규칙을 생략한다 — 서비스가 `challenge_id` 422 로 답한다.
     *
     * @param  Validator  $validator  검증기
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $this->appendPasswordReuseCheck(
            $validator,
            fn () => $this->challengeUser(),
            ['challenge_id'],
            true,
        );
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
            'password.required' => __('validation.auth.password.required'),
            'password.min' => __('validation.auth.password.min'),
            'password_confirmation.required' => __('validation.auth.password_change.password_confirmation_required'),
            'password_confirmation.same' => __('validation.auth.password.confirmed'),
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

    /**
     * 변경 단계 식별자가 지금 이 경로에 유효하면 그 회원을 돌려줍니다.
     *
     * @return User|null 회원 (식별자가 없거나 무효면 null)
     */
    private function challengeUser(): ?User
    {
        if ($this->challengeResolved) {
            return $this->challengeUser;
        }

        $this->challengeResolved = true;
        $id = $this->input('challenge_id');

        if (! is_string($id) || $id === '') {
            return null;
        }

        $challenges = app(PasswordChangeChallengeService::class);
        $payload = $challenges->peek($id);

        if ($payload === null) {
            return null;
        }

        $user = app(UserRepositoryInterface::class)->findById((int) $payload['user_id']);

        if ($user === null || ! $challenges->matches($payload, $user, $this->loginGuard())) {
            return null;
        }

        return $this->challengeUser = $user;
    }
}
