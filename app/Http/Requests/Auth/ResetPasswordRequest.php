<?php

namespace App\Http\Requests\Auth;

use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Http\Requests\Concerns\ChecksPasswordReuse;
use App\Models\User;
use App\Rules\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ResetPasswordRequest extends FormRequest
{
    use ChecksPasswordReuse;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'token' => 'required|string',
            'email' => ['required', 'email', Rule::exists(User::class, 'email')],
            'password' => ['required', 'string', 'confirmed', ...PasswordPolicy::rules($this->identifierEmail())],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.auth.reset_password_validation_rules', $rules, $this);
    }

    /**
     * 재사용 금지 검사를 재설정 토큰 검증 **뒤에만** 붙입니다.
     *
     * 이 라우트는 토큰을 서비스에서야 검증하고 요청 제한도 없다. 토큰 확인 전에 재사용 검사를 돌리면
     * 남의 이메일과 아무 토큰으로 "최근에 쓴 비밀번호입니다" 오류를 받아 현재·이전 비밀번호를 맞혀 볼 수
     * 있다 — 그래서 서비스와 같은 조건(기록 존재·해시 일치·유효시간)으로 토큰을 먼저 확인한다.
     *
     * @param  Validator  $validator  검증기
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $this->appendPasswordReuseCheck(
            $validator,
            fn () => app(UserRepositoryInterface::class)->findByEmail((string) $this->input('email')),
            ['token', 'email'],
            false,
            fn () => $this->resetTokenIsValid(),
        );
    }

    /**
     * 요청의 재설정 토큰이 유효한지 확인합니다 (서비스 검증과 같은 조건).
     *
     * @return bool 기록이 있고 해시가 맞고 유효시간 안이면 true
     */
    private function resetTokenIsValid(): bool
    {
        $token = $this->input('token');
        $email = $this->input('email');

        if (! is_string($token) || $token === '' || ! is_string($email) || $email === '') {
            return false;
        }

        $record = app(PasswordResetTokenRepositoryInterface::class)->findByEmail($email);

        if ($record === null || ! Hash::check($token, $record->token)) {
            return false;
        }

        $expireMinutes = (int) config('auth.passwords.users.expire', 60);

        return ! $record->created_at->copy()->addMinutes($expireMinutes)->isPast();
    }

    /**
     * 아이디(이메일 앞부분) 포함 판정에 쓸 이메일을 반환합니다.
     *
     * @return string|null 입력 이메일 (없으면 null)
     */
    private function identifierEmail(): ?string
    {
        $email = $this->input('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => __('validation.auth.token.required'),
            'email.required' => __('validation.auth.email.required'),
            'email.email' => __('validation.auth.email.email'),
            'email.exists' => __('validation.auth.email.exists'),
            'password.required' => __('validation.auth.password.required'),
            'password.min' => __('validation.auth.password.min'),
            'password.confirmed' => __('validation.auth.password.confirmed'),
        ];
    }
}
