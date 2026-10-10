<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Http\Requests\Concerns\ChecksPasswordReuse;
use App\Models\User;
use App\Rules\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 비밀번호 변경 요청 클래스
 *
 * 사용자가 자신의 비밀번호를 변경할 때 사용되는 validation 규칙을 정의합니다.
 */
class ChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'confirmed', ...PasswordPolicy::rules($this->user()?->email)],
            'password_confirmation' => ['required', 'string'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.change_password_validation_rules', $rules, $this);
    }

    /**
     * 재사용 금지 검사를 현재 비밀번호 확인 **뒤에만** 붙입니다 (추측 오라클 차단).
     *
     * @param  Validator  $validator  검증기
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $this->appendPasswordReuseCheck(
            $validator,
            fn () => $this->user() instanceof User ? $this->user() : null,
            ['current_password'],
        );
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => __('user.validation.current_password_required'),
            'current_password.current_password' => __('user.validation.current_password_invalid'),
            'password.required' => __('user.validation.password_required'),
            'password.confirmed' => __('user.validation.password_confirmed'),
            'password.min' => __('user.validation.password_min'),
            'password_confirmation.required' => __('user.validation.password_confirmation_required'),
        ];
    }
}
