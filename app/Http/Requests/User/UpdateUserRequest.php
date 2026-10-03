<?php

namespace App\Http\Requests\User;

use App\Enums\PasswordExpiryPolicy;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Http\Requests\Concerns\ChecksPasswordReuse;
use App\Models\Role;
use App\Models\User;
use App\Rules\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    use ChecksPasswordReuse;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 permission 미들웨어에서 검증)
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
     * @return array<string, ValidationRule|array<mixed>|string>
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
            'password' => ['nullable', 'confirmed', ...PasswordPolicy::rules($this->identifierEmail())],
            'password_expiry_policy' => ['nullable', 'string', Rule::in(PasswordExpiryPolicy::values())],
            'language' => 'nullable|string|in:'.$supportedLocales,
            'country' => 'nullable|string|size:2|alpha',
            'timezone' => ['nullable', 'string', 'timezone'],
            // 어휘는 Enum 단독 소유다 — 일괄 변경 경로와 같은 목록을 써야 강도가 갈리지 않는다.
            'status' => ['nullable', 'string', Rule::in(UserStatus::values())],

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

        // 관리자 본인 계정의 비밀번호를 바꾸면 현재 비밀번호를 확인한다(D19) — 자리를 비운 사이 열린 세션으로
        // 비밀번호를 바꿔 계정을 가져가는 경로를 막는다. 다른 회원의 비밀번호를 정하는 것은 종전대로다.
        if ($this->isSelfUpdate()) {
            $rules['current_password'] = ['required_with:password', 'nullable', 'string', 'current_password:sanctum'];
        }

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.update_validation_rules', $rules, $this);
    }

    /**
     * 본인 계정을 고칠 때만 재사용 금지 검사를 현재 비밀번호 확인 **뒤에** 붙입니다.
     *
     * 다른 회원의 비밀번호는 관리자가 정해 주는 것이라 재사용 검사를 하지 않는다(D11) — 그 회원은
     * 다음 로그인 때 직접 바꿔야 한다.
     *
     * @param  Validator  $validator  검증기
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        if (! $this->isSelfUpdate()) {
            return;
        }

        $this->appendPasswordReuseCheck(
            $validator,
            fn () => $this->targetUser(),
            ['current_password'],
        );
    }

    /**
     * 수정 대상 회원(라우트 바인딩)을 반환합니다.
     *
     * @return User|null 대상 회원
     */
    private function targetUser(): ?User
    {
        $target = $this->route('user');

        return $target instanceof User ? $target : null;
    }

    /**
     * 요청자가 자기 계정을 고치는지 판정합니다.
     *
     * 화면 판정과 별개로 서버가 행위자 = 대상을 직접 본다.
     *
     * @return bool 본인 계정이면 true
     */
    private function isSelfUpdate(): bool
    {
        $target = $this->targetUser();
        $actor = $this->user();

        return $target !== null && $actor instanceof User && (int) $actor->getKey() === (int) $target->getKey();
    }

    /**
     * 아이디(이메일 앞부분) 포함 판정에 쓸 이메일을 반환합니다 (입력값 ?? 대상 회원).
     *
     * @return string|null 이메일 (없으면 null)
     */
    private function identifierEmail(): ?string
    {
        $email = $this->input('email');

        if (is_string($email) && $email !== '') {
            return $email;
        }

        return $this->targetUser()?->email;
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
            'password_expiry_policy.string' => __('user.validation.password_expiry_policy_invalid'),
            'password_expiry_policy.in' => __('user.validation.password_expiry_policy_invalid'),
            'current_password.required_with' => __('user.validation.current_password_required'),
            'current_password.current_password' => __('user.validation.current_password_invalid'),

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
