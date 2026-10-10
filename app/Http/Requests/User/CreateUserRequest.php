<?php

namespace App\Http\Requests\User;

use App\Enums\PasswordExpiryPolicy;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use App\Rules\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
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
        $supportedLocales = implode(',', config('app.supported_locales', ['ko', 'en']));

        $rules = [
            // 기본 정보
            'name' => 'required|string|max:255',
            'nickname' => 'nullable|string|max:50',
            'email' => ['required', 'email', Rule::unique(User::class, 'email'), 'max:255'],
            // 관리자가 정해 준 비밀번호는 재사용 검사를 하지 않는다(D11) — 회원이 다음 로그인 때 직접 바꾼다.
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules($this->identifierEmail())],
            'password_expiry_policy' => ['nullable', 'string', Rule::in(PasswordExpiryPolicy::values())],
            'language' => 'nullable|string|in:'.$supportedLocales,
            'country' => 'nullable|string|size:2|alpha',
            'timezone' => ['nullable', 'string', 'timezone'],
            // 어휘는 Enum 단독 소유다 — 여기에 목록을 박아 두면 일괄 변경 경로
            // (`Rule::in(UserStatus::values())`)와 어휘가 갈려, 일괄은 통과하는 값이
            // 개별에서만 거부되는 강도 비대칭이 생긴다.
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

            // 역할 정보 (객체 배열 또는 id 배열 모두 허용, 생략 가능)
            // 생략하거나 비워 보내면 서비스가 기본 역할('user')을 배정한다(UserService::createUser).
            // 필수로 두면 역할 부여 권한(core.users.update) 없이 회원 생성 권한만 가진 운영자는
            // 역할 칸이 잠긴 폼으로 등록하므로 늘 422 가 난다 — 부여 가능 여부는 화면이
            // `can_assign_roles` 로, 부여 범위는 서버 상한(PermissionEscalationGuard)이 따로 정한다.
            'roles' => 'nullable|array',
            'roles.*.id' => ['required', 'integer', Rule::exists(Role::class, 'id')],
            'role_ids' => 'nullable|array',
            'role_ids.*' => ['integer', Rule::exists(Role::class, 'id')],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.create_validation_rules', $rules, $this);
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
            'password.required' => __('user.validation.password_required'),
            'password.confirmed' => __('user.validation.password_confirmed'),
            'password.min' => __('user.validation.password_min'),
            'language.in' => __('user.validation.language_invalid'),
            'country.size' => __('user.validation.country_size'),
            'country.alpha' => __('user.validation.country_alpha'),
            'timezone.in' => __('user.validation.timezone_invalid'),
            'status.in' => __('user.validation.status_invalid'),
            'password_expiry_policy.string' => __('user.validation.password_expiry_policy_invalid'),
            'password_expiry_policy.in' => __('user.validation.password_expiry_policy_invalid'),

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
        ];
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
     * 유효성 검사를 위해 데이터를 준비합니다.
     *
     * role_ids와 roles가 동시에 전송될 경우 role_ids를 우선하여 roles를 제거합니다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('role_ids') && $this->has('roles')) {
            $this->getInputSource()->remove('roles');
        }

        $this->merge([
            'language' => $this->language ?? 'ko',
            'status' => $this->status ?? 'active',
        ]);
    }
}
