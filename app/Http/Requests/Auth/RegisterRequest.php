<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use App\Models\User;
use App\Rules\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 처리)
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
            'name' => 'required|string|max:255',
            'nickname' => 'nullable|string|max:50',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            // 작성 규칙(조합·추측 방지)까지 함께 — 아이디 판정은 입력한 이메일로 한다.
            'password' => ['required', 'string', 'confirmed', ...PasswordPolicy::rules($this->identifierEmail())],
            'mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\+\(\)\s]+$/'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\+\(\)\s]+$/'],
            'language' => 'nullable|string|in:'.implode(',', config('app.supported_locales', ['ko', 'en'])),
            'agree_terms' => 'accepted',
            'agree_privacy' => 'accepted',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.auth.register_validation_rules', $rules, $this);
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
            'name.required' => __('validation.auth.name.required'),
            'name.max' => __('validation.auth.name.max'),
            'nickname.max' => __('validation.auth.nickname.max'),
            'email.required' => __('validation.auth.email.required'),
            'email.email' => __('validation.auth.email.email'),
            'email.unique' => __('validation.auth.email.unique'),
            'password.required' => __('validation.auth.password.required'),
            'password.min' => __('validation.auth.password.min'),
            'password.confirmed' => __('validation.auth.password.confirmed'),
            'mobile.max' => __('validation.auth.mobile.max'),
            'mobile.regex' => __('validation.auth.mobile.regex'),
            'phone.max' => __('validation.auth.phone.max'),
            'phone.regex' => __('validation.auth.phone.regex'),
            'agree_terms.accepted' => __('validation.auth.agree_terms.accepted'),
            'agree_privacy.accepted' => __('validation.auth.agree_privacy.accepted'),
        ];
    }
}
