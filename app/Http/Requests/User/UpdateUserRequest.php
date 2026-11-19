<?php

namespace App\Http\Requests\User;

use App\Enums\PasswordExpiryPolicy;
use App\Enums\UserStatus;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Http\Requests\Concerns\ChecksPasswordReuse;
use App\Http\Requests\Concerns\UsesMemberFields;
use App\Models\Role;
use App\Models\User;
use App\Rules\PasswordPolicy;
use App\Services\Auth\ReauthenticationService;
use App\Services\UserLockedFieldsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    use UsesMemberFields;

    /** 종전 규칙의 키 순서 — 오류가 여럿일 때 응답에 실리는 첫 문구가 달라지지 않게 유지한다 */
    private const LEGACY_RULE_ORDER = ['name', 'nickname', 'email', 'password', 'password_expiry_policy', 'language', 'country', 'timezone', 'status', 'homepage', 'mobile', 'phone', 'zipcode', 'address', 'address_detail', 'signature', 'bio', 'admin_memo', 'roles', 'roles.*.id', 'role_ids', 'role_ids.*'];

    use ChecksPasswordReuse;

    /**
     * 비밀번호 없는 관리자의 본인 수정 요청이 옛 화면(현재 비밀번호 칸을 채워 보냄)에서 왔는지 여부.
     *
     * 참이면 토큰 누락을 검증 오류(422) 대신 본인인증 요구(428)로 답한다.
     */
    private bool $legacyPasswordReauth = false;

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

        // 비밀번호 없는 관리자가 본인 계정을 고치며 현재 비밀번호를 보냈다 = 그 회원을 모르는 옛 화면(7.1.x)이다(칸이 보여
        // 무엇이든 넣는다). 대조할 비밀번호가 없으니 값은 버리고, 토큰 없이 비밀번호를 정하려 하면 422 대신 본인인증을
        // 요구한다(passedValidation, 428) — 엔진이 인증 창을 열고 확인이 끝나면 토큰을 붙여 이 요청을 다시 보낸다.
        // 현재 비밀번호를 비워 보낸 요청(새 화면)은 종전 계약(토큰 필수 422) 그대로다.
        if ($this->isSelfUpdate() && $this->targetUser()?->hasPassword() === false && $this->has('current_password')) {
            $current = $this->input('current_password');
            $this->legacyPasswordReauth = is_string($current) && $current !== '';
            $this->getInputSource()->remove('current_password');
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

        // 회원 항목(이름, 닉네임, 연락처, 주소 등과 운영자 추가 항목)의 규칙은 운영자 설정에서 온다 — 아래는 항목이 아닌 고정 규칙이다.
        // 대상 회원의 현재 값이 빈 필수 항목은 선택으로 본다(새로 필수가 된 항목 때문에 다른 칸을 못 고치는 일을 막는다).
        $rules = $this->withMemberFieldRules([
            // 기본 정보
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($userId),
            ],
            'password' => ['nullable', 'confirmed', ...PasswordPolicy::rules($this->identifierEmail())],
            'password_expiry_policy' => ['nullable', 'string', Rule::in(PasswordExpiryPolicy::values())],
            // 어휘는 Enum 단독 소유다 — 일괄 변경 경로와 같은 목록을 써야 강도가 갈리지 않는다.
            'status' => ['nullable', 'string', Rule::in(UserStatus::values())],

            // 연락처 정보

            // 주소 정보

            // 추가 정보
            'admin_memo' => 'nullable|string|max:5000',

            // 역할 정보 (객체 배열 또는 id 배열 모두 허용, 하나 이상 필수)
            'roles' => 'required_without:role_ids|array|min:1',
            'roles.*.id' => ['required', 'integer', Rule::exists(Role::class, 'id')],
            'role_ids' => 'required_without:roles|array|min:1',
            'role_ids.*' => ['integer', Rule::exists(Role::class, 'id')],
        ], self::LEGACY_RULE_ORDER);

        // 관리자 본인 계정의 비밀번호를 바꾸면 현재 비밀번호를 확인한다(D19) — 자리를 비운 사이 열린 세션으로
        // 비밀번호를 바꿔 계정을 가져가는 경로를 막는다. 다른 회원의 비밀번호를 정하는 것은 종전대로다.
        // 비밀번호가 없는 관리자(외부 인증 전용)는 확인할 현재 비밀번호가 없다 — 비밀번호를 처음 정할 때는 이메일 인증번호
        // 확인으로 받은 재확인 토큰을 대신 받는다(같은 위협: 자리를 비운 사이 열린 세션). 토큰은 검증이 모두 끝난 뒤 소비한다.
        if ($this->isSelfUpdate()) {
            if ($this->targetUser()?->hasPassword() === false) {
                $rules['current_password'] = ['prohibited'];
                // 옛 화면은 토큰을 모른다 — 누락은 검증 오류로 끊지 않고 passedValidation 이 본인인증(428)으로 받는다.
                $rules['verification_token'] = [...($this->legacyPasswordReauth ? [] : ['required_with:password']), 'nullable', 'string', 'max:128', function (string $attribute, mixed $value, \Closure $fail) {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    $target = $this->targetUser();

                    if ($target === null || app(ReauthenticationService::class)->check($target, ['verification_token' => $value]) !== ReauthenticationService::PASSED) {
                        $fail(__('user.reauthentication_invalid'));
                    }
                }];
            } else {
                $rules['current_password'] = ['required_with:password', 'nullable', 'string', 'current_password:sanctum'];
            }
        }

        // 외부(회사 계정)가 관리하는 칸은 현재 값과 같아야 한다 — 요청에 그 칸이 없으면 통과(7.2.0)
        $rules = app(UserLockedFieldsService::class)->applyRules($rules, $this->targetUser(), UserLockedFieldsService::SURFACE_ADMIN);

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
            ['current_password', 'verification_token'],
        );
    }

    /**
     * 검증이 모두 통과하면 재확인 토큰을 소비합니다 (한 번만 쓸 수 있게).
     *
     * 옛 화면에서 온 비밀번호 첫 설정이 토큰 없이 왔으면 본인인증을 요구한다(428) — 다른 입력 오류가 먼저 보이도록 검증이
     * 모두 통과한 뒤에 건다.
     *
     * @return void
     *
     * @throws IdentityVerificationRequiredException 옛 화면의 비밀번호 첫 설정에 토큰이 없을 때
     */
    protected function passedValidation(): void
    {
        $token = $this->input('verification_token');
        $target = $this->targetUser();
        $password = $this->input('password');

        if ($this->legacyPasswordReauth && is_string($password) && $password !== '' && (! is_string($token) || $token === '')) {
            throw app(ReauthenticationService::class)->challengeFor($this);
        }

        // 옛 화면(회원 항목 표식 없음)이라 보내지 않은 필수 항목은 저장된 값을 그대로 두고 건너뛴다 — 그 사실을 남긴다.
        $this->noteLegacyMemberFieldScreen();

        if ($this->isSelfUpdate() && $target !== null && is_string($token) && $token !== '') {
            app(ReauthenticationService::class)->consume($target, ['verification_token' => $token]);
        }
    }

    /**
     * 관리자 수정 표면의 회원 항목 문맥을 반환합니다.
     *
     * @return FieldContext 문맥
     */
    protected function memberFieldContext(): FieldContext
    {
        $actor = $this->user();

        return FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $this->targetUser(), $actor instanceof User ? $actor : null);
    }

    /**
     * 옛 화면 호환을 받는다 — 정의 버전(`fields_version`)을 싣지 않은 수정 요청은 회원 항목 조각이 없는 옛 관리자 화면(7.1.x 템플릿)에서 왔다고 본다.
     *
     * 그 요청은 운영자 추가 항목과 확장 선언 항목의 필수 규칙을 「값을 보냈을 때만」으로 늦춘다. 보내지 않은 항목은 저장된 값이 그대로 남는다.
     *
     * @return bool 항상 true
     */
    protected function acceptsLegacyMemberFieldScreen(): bool
    {
        return true;
    }

    /**
     * 검증 속성명 — 운영자 추가 항목과 확장 항목의 이름을 요청 로케일로 싣는다.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return $this->withMemberFieldAttributes();
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
            'current_password.prohibited' => __('user.validation.current_password_not_set'),
            'verification_token.required_with' => __('user.password_not_set'),

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
