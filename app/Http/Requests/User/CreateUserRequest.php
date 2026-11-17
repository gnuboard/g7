<?php

namespace App\Http\Requests\User;

use App\Enums\PasswordExpiryPolicy;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Http\Requests\Concerns\UsesMemberFields;
use App\Models\Role;
use App\Models\User;
use App\Rules\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateUserRequest extends FormRequest
{
    use UsesMemberFields;

    /** 종전 규칙의 키 순서 — 오류가 여럿일 때 응답에 실리는 첫 문구가 달라지지 않게 유지한다 */
    private const LEGACY_RULE_ORDER = ['name', 'nickname', 'email', 'create_without_password', 'password', 'password_expiry_policy', 'language', 'country', 'timezone', 'status', 'homepage', 'mobile', 'phone', 'zipcode', 'address', 'address_detail', 'signature', 'bio', 'admin_memo', 'roles', 'roles.*.id', 'role_ids', 'role_ids.*'];

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
        // 회원 항목(이름, 닉네임, 연락처, 주소 등과 운영자 추가 항목)의 규칙은 운영자 설정에서 온다 — 아래는 항목이 아닌 고정 규칙이다.
        $rules = $this->withMemberFieldRules([
            // 기본 정보
            'email' => ['required', 'email', Rule::unique(User::class, 'email'), 'max:255'],
            // 관리자가 정해 준 비밀번호는 재사용 검사를 하지 않는다(D11) — 회원이 다음 로그인 때 직접 바꾼다.
            // 「비밀번호 없이 만들기」(7.2.0) — 외부 인증(회사 계정)으로만 로그인할 회원을 미리 만든다. 켜면 비밀번호를 받지 않는다.
            'create_without_password' => ['sometimes', 'boolean'],
            'password' => $this->boolean('create_without_password')
                ? ['prohibited']
                : ['required', 'confirmed', ...PasswordPolicy::rules($this->identifierEmail())],
            'password_expiry_policy' => ['nullable', 'string', Rule::in(PasswordExpiryPolicy::values())],
            // 어휘는 Enum 단독 소유다 — 여기에 목록을 박아 두면 일괄 변경 경로
            // (`Rule::in(UserStatus::values())`)와 어휘가 갈려, 일괄은 통과하는 값이
            // 개별에서만 거부되는 강도 비대칭이 생긴다.
            'status' => ['nullable', 'string', Rule::in(UserStatus::values())],

            // 연락처 정보

            // 주소 정보

            // 추가 정보
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
        ], self::LEGACY_RULE_ORDER);

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
        return $this->withMemberFieldMessages([
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
            'password.prohibited' => __('user.validation.password_prohibited_without_password'),
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
        ]);
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
     * 관리자 추가 표면의 회원 항목 문맥을 반환합니다.
     *
     * @return FieldContext 문맥
     */
    protected function memberFieldContext(): FieldContext
    {
        $actor = $this->user();

        return FieldContext::for(FieldContext::SURFACE_ADMIN_CREATE, null, $actor instanceof User ? $actor : null);
    }

    /**
     * 옛 화면 호환을 받는다 — 정의 버전(`fields_version`)을 싣지 않은 추가 요청은 회원 항목 조각이 없는 옛 관리자 화면(7.1.x 템플릿)에서 왔다고 본다.
     *
     * 그 요청은 옛 화면에 입력칸이 없던 항목(운영자 추가 항목, 확장 선언 항목)의 필수 규칙을 「값을 보냈을 때만」으로 늦춰 통과시킨다.
     * 만든 회원에게 추가 정보 입력 대기 표시는 남기지 않는다 — 빈 항목은 템플릿을 7.2.0 으로 올린 뒤 운영자가 채운다.
     *
     * @return bool 항상 true
     */
    protected function acceptsLegacyMemberFieldScreen(): bool
    {
        return true;
    }

    /**
     * 검증을 마친 뒤 옛 화면 호환으로 건너뛴 항목을 로그에 남깁니다.
     *
     * @return void 반환값 없음
     */
    protected function passedValidation(): void
    {
        $this->noteLegacyMemberFieldScreen();
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
