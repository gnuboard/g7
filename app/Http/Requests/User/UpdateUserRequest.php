<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 데이터 전처리
     *
     * API 응답에서 로드된 roles(객체 배열)와 사용자가 선택한 role_ids가
     * 동시에 전송될 수 있으므로, role_ids가 있으면 roles를 제거합니다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('role_ids') && $this->has('roles')) {
            $this->getInputSource()->remove('roles');
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('user');
        $supportedLocales = implode(',', config('app.supported_locales', ['ko', 'en']));

        $rules = [
            // 기본 정보
            'name' => 'required|string|max:255',
            'nickname' => 'nullable|string|max:50',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($userId),
            ],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'language' => 'nullable|string|in:'.$supportedLocales,
            'country' => 'nullable|string|size:2|alpha',
            'timezone' => ['nullable', 'string', Rule::in(config('app.supported_timezones', ['Asia/Seoul']))],
            'status' => 'nullable|string|in:active,inactive,blocked,withdrawn',

            // 연락처 정보
            'homepage' => 'nullable|url|max:255',
            'mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\+\(\)\s]+$/'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-\+\(\)\s]+$/'],

            // 주소 정보
            'zipcode' => 'nullable|string|max:10',
            'address' => 'nullable|string|max:255',
            'address_detail' => 'nullable|string|max:255',

            // 추가 정보
            'signature' => 'nullable|string|max:1000',
            'bio' => 'nullable|string|max:5000',
            'admin_memo' => 'nullable|string|max:5000',

            // 역할 정보 (객체 배열 또는 id 배열 모두 허용, 하나 이상 필수)
            'roles' => 'required_without:role_ids|array|min:1',
            'roles.*.id' => ['required', 'integer', Rule::exists(Role::class, 'id')],
            'role_ids' => 'required_without:roles|array|min:1',
            'role_ids.*' => ['integer', Rule::exists(Role::class, 'id')],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.update_validation_rules', $rules, $this);
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
            'language.in' => __('user.validation.language_invalid'),
            'country.size' => __('user.validation.country_size'),
            'country.alpha' => __('user.validation.country_alpha'),
            'timezone.in' => __('user.validation.timezone_invalid'),
            'status.in' => __('user.validation.status_invalid'),

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
            'admin_memo.max' => __('user.validation.admin_memo_max'),

            // 역할 정보
            'roles.required_without' => __('user.validation.roles_required'),
            'roles.min' => __('user.validation.roles_min'),
            'role_ids.required_without' => __('user.validation.roles_required'),
            'role_ids.min' => __('user.validation.roles_min'),
        ];
    }
}
