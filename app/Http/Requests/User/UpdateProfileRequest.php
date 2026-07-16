<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * 사용자 프로필 업데이트 요청 클래스
 *
 * 일반 사용자가 자신의 프로필 정보를 수정할 때 사용되는 validation 규칙을 정의합니다.
 * 관리자용 UpdateUserRequest와 달리 사용자 본인만 수정할 수 있는 필드들로 제한됩니다.
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = Auth::id();
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);

        $rules = [
            // 기본 정보
            'name' => ['required', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($userId)],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'current_password' => ['required_with:password', 'current_password'],
            'language' => ['nullable', 'string', Rule::in($supportedLocales)],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'timezone' => ['nullable', 'string', 'timezone'],

            // 연락처 정보
            'homepage' => ['nullable', 'url', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\+\(\)\s]+$/'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\+\(\)\s]+$/'],

            // 주소 정보
            'zipcode' => ['nullable', 'string', 'max:10'],
            'address' => ['nullable', 'string', 'max:255'],
            'address_detail' => ['nullable', 'string', 'max:255'],

            // 추가 정보
            'signature' => ['nullable', 'string', 'max:1000'],
            'bio' => ['nullable', 'string', 'max:5000'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.update_profile_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // 기본 정보
            'name.required' => __('user.validation.name_required'),
            'name.string' => __('user.validation.name_string'),
            'name.max' => __('user.validation.name_max'),
            'nickname.string' => __('user.validation.nickname_string'),
            'nickname.max' => __('user.validation.nickname_max'),
            'email.required' => __('user.validation.email_required'),
            'email.email' => __('user.validation.email_invalid'),
            'email.unique' => __('user.validation.email_unique'),
            'email.max' => __('user.validation.email_max'),
            'password.confirmed' => __('user.validation.password_confirmed'),
            'password.min' => __('user.validation.password_min'),
            'current_password.required_with' => __('user.validation.current_password_required'),
            'current_password.current_password' => __('user.validation.current_password_invalid'),
            'language.in' => __('user.validation.language_invalid'),
            'country.size' => __('user.validation.country_size'),
            'country.alpha' => __('user.validation.country_alpha'),
            'timezone.in' => __('user.validation.timezone_invalid'),

            // 연락처 정보
            'homepage.url' => __('user.validation.homepage_url'),
            'homepage.max' => __('user.validation.homepage_max'),
            'mobile.max' => __('user.validation.mobile_max'),
            'mobile.regex' => __('user.validation.mobile_regex'),
            'phone.max' => __('user.validation.phone_max'),
            'phone.regex' => __('user.validation.phone_regex'),

            // 주소 정보
            'zipcode.max' => __('user.validation.zipcode_max'),
            'address.max' => __('user.validation.address_max'),
            'address_detail.max' => __('user.validation.address_detail_max'),

            // 추가 정보
            'signature.max' => __('user.validation.signature_max'),
            'bio.max' => __('user.validation.bio_max'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // 빈 password 필드 제거 (null로 변환하여 validation에서 무시되도록)
        if ($this->password === '') {
            $this->merge([
                'password' => null,
                'password_confirmation' => null,
            ]);
        }
    }

}
